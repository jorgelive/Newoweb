<?php

declare(strict_types=1);

namespace App\Agent\Alexa;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Comprueba que una petición viene de verdad de Amazon.
 *
 * 🔑 **Esto ES la autenticación del endpoint.** `/alexa` cuelga del host de API, que no está en
 * `access_control` y por tanto cae en `PUBLIC_ACCESS`; y a diferencia del asistente del panel
 * aquí no hay `#[IsGranted]` que valga, porque Alexa no manda cookie de sesión ni cabecera de
 * autorización. Lo único que separa este endpoint de un POST anónimo contra el PMS es lo que
 * hace esta clase. Si algo de aquí se relaja, el endpoint queda abierto.
 *
 * Amazon lo exige además para certificar el skill. El procedimiento es suyo y no admite
 * atajos; los pasos van en el orden en que descartan más barato:
 *
 *   1. La URL del certificado tiene la forma que Amazon promete (si no, ni se descarga).
 *   2. El certificado vale para `echo-api.amazon.com` y está en fecha.
 *   3. La cadena sube hasta una CA de confianza del sistema.
 *   4. La firma del CUERPO CRUDO cuadra con la clave pública del certificado.
 *   5. La marca de tiempo no tiene más de 150 s (evita repetir una petición capturada).
 *
 * ⚠️ El paso 4 se hace sobre el cuerpo **tal cual llegó**, byte a byte. Decodificar el JSON y
 * volver a serializarlo cambia espacios y orden de claves, y la firma deja de cuadrar: por eso
 * el controlador pasa `$request->getContent()` y no el array ya parseado.
 *
 * Ver docs/AgentVoz.md §3.
 */
final readonly class AlexaFirma
{
    /** Ventana de Amazon. Más allá, la petición se considera repetida. */
    private const int TOLERANCIA_SEGUNDOS = 150;

    /** El certificado tiene que servir para este nombre; es la marca de la casa. */
    private const string SAN_EXIGIDO = 'echo-api.amazon.com';

    /** Amazon firma con RSA-SHA1. Es heredado, pero es lo que hay: no es elección nuestra. */
    private const int ALGORITMO = OPENSSL_ALGO_SHA1;

    /**
     * Los certificados rotan pero no en cada petición: descargarlos siempre añadiría una ida y
     * vuelta a S3 dentro de los 8 s que da Alexa para responder.
     */
    private const int CACHE_SEGUNDOS = 86400;

    public function __construct(
        private HttpClientInterface $http,
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param string $cuerpo El contenido crudo de la petición, sin tocar.
     */
    public function esValida(Request $peticion, string $cuerpo): bool
    {
        // Symfony normaliza las cabeceras, así que da igual que Alexa las mande en
        // `SignatureCertChainUrl` o en `signaturecertchainurl` según la versión.
        $url = (string) $peticion->headers->get('SignatureCertChainUrl');
        $firma = (string) $peticion->headers->get('Signature');

        if ($url === '' || $firma === '') {
            return $this->rechazar('faltan las cabeceras de firma');
        }

        if (!$this->urlDeCertificadoEsLegitima($url)) {
            return $this->rechazar(sprintf('URL de certificado no legítima: %s', $url));
        }

        $cadena = $this->descargarCadena($url);
        if ($cadena === null) {
            return $this->rechazar('no se pudo descargar el certificado');
        }

        if (!$this->certificadoEsValido($cadena)) {
            return $this->rechazar('el certificado no es válido para Alexa');
        }

        $binaria = base64_decode($firma, true);
        if ($binaria === false) {
            return $this->rechazar('la firma no es base64');
        }

        $clave = openssl_pkey_get_public($cadena[0]);
        if ($clave === false) {
            return $this->rechazar('no se pudo leer la clave pública del certificado');
        }

        if (openssl_verify($cuerpo, $binaria, $clave, self::ALGORITMO) !== 1) {
            return $this->rechazar('la firma no corresponde al cuerpo recibido');
        }

        return true;
    }

    /**
     * La marca de tiempo va en el JSON ya parseado, no en una cabecera: se comprueba aparte
     * para no obligar a esta clase a decodificar el cuerpo que acaba de verificar.
     */
    public function marcaDeTiempoEsReciente(?string $timestamp): bool
    {
        if ($timestamp === null || $timestamp === '') {
            return $this->rechazar('la petición no trae marca de tiempo');
        }

        $momento = strtotime($timestamp);
        if ($momento === false) {
            return $this->rechazar(sprintf('marca de tiempo ilegible: %s', $timestamp));
        }

        // En valor absoluto a propósito: un reloj adelantado también es sospechoso.
        if (abs(time() - $momento) > self::TOLERANCIA_SEGUNDOS) {
            return $this->rechazar(sprintf('marca de tiempo fuera de ventana: %s', $timestamp));
        }

        return true;
    }

    /**
     * Amazon promete una forma exacta para esta URL, y comprobarla es lo que impide que nos
     * hagan descargar «su certificado» desde un servidor cualquiera.
     *
     * El `realpath` sobre la ruta no es adorno: sin él, `/echo.api/../evil/cert.pem` pasa el
     * `str_starts_with` y apunta a otro sitio.
     */
    private function urlDeCertificadoEsLegitima(string $url): bool
    {
        $partes = parse_url($url);
        if ($partes === false || !isset($partes['scheme'], $partes['host'], $partes['path'])) {
            return false;
        }

        if (strtolower($partes['scheme']) !== 'https') {
            return false;
        }

        if (strtolower($partes['host']) !== 's3.amazonaws.com') {
            return false;
        }

        if (isset($partes['port']) && $partes['port'] !== 443) {
            return false;
        }

        $ruta = $this->normalizarRuta($partes['path']);

        return str_starts_with($ruta, '/echo.api/');
    }

    /** Resuelve `.` y `..` sin tocar el disco: `realpath()` exigiría que la ruta existiera. */
    private function normalizarRuta(string $ruta): string
    {
        $salida = [];

        foreach (explode('/', $ruta) as $tramo) {
            if ($tramo === '' || $tramo === '.') {
                continue;
            }

            if ($tramo === '..') {
                array_pop($salida);
                continue;
            }

            $salida[] = $tramo;
        }

        return '/' . implode('/', $salida);
    }

    /**
     * @return list<string>|null Certificados PEM, el de la hoja primero.
     */
    private function descargarCadena(string $url): ?array
    {
        try {
            /** @var list<string>|null $cadena */
            $cadena = $this->cache->get(
                'alexa_cert_' . hash('sha256', $url),
                function (ItemInterface $item) use ($url): ?array {
                    $item->expiresAfter(self::CACHE_SEGUNDOS);

                    $pem = $this->http->request('GET', $url, ['timeout' => 5])->getContent();
                    $certificados = $this->separarPem($pem);

                    // Un `null` cacheado 24 h dejaría el skill muerto hasta que caducara el
                    // item; mejor no guardar nada y reintentar en la siguiente petición.
                    if ($certificados === []) {
                        $item->expiresAfter(0);

                        return null;
                    }

                    return $certificados;
                }
            );
        } catch (Throwable $e) {
            $this->logger->warning('Alexa: fallo descargando el certificado: ' . $e->getMessage());

            return null;
        }

        return $cadena;
    }

    /** @return list<string> */
    private function separarPem(string $pem): array
    {
        $encontrados = [];
        preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $encontrados);

        // `preg_match_all` devuelve el grupo ya como lista secuencial.
        return $encontrados[0];
    }

    /**
     * @param list<string> $cadena
     */
    private function certificadoEsValido(array $cadena): bool
    {
        $hoja = openssl_x509_parse($cadena[0]);
        if ($hoja === false) {
            return false;
        }

        $ahora = time();
        if (($hoja['validFrom_time_t'] ?? 0) > $ahora || ($hoja['validTo_time_t'] ?? 0) < $ahora) {
            return false;
        }

        if (!$this->tieneSanDeAlexa($hoja)) {
            return false;
        }

        return $this->cadenaSubeAUnaCaDeConfianza($cadena);
    }

    /**
     * @param array<string, mixed> $hoja
     */
    private function tieneSanDeAlexa(array $hoja): bool
    {
        $extensiones = $hoja['extensions'] ?? [];
        $san = is_array($extensiones) ? (string) ($extensiones['subjectAltName'] ?? '') : '';

        foreach (explode(',', $san) as $entrada) {
            if (trim($entrada) === 'DNS:' . self::SAN_EXIGIDO) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica la cadena contra el almacén de CA del sistema.
     *
     * Los intermedios llegan en la misma descarga, así que se pasan como «no confiables» y
     * OpenSSL los usa de puente hasta una raíz que sí lo sea. Sin este paso, cualquiera podría
     * presentar un certificado autofirmado con el SAN correcto: los dos controles anteriores
     * lo darían por bueno.
     *
     * @param list<string> $cadena
     */
    private function cadenaSubeAUnaCaDeConfianza(array $cadena): bool
    {
        $intermedios = array_slice($cadena, 1);
        $archivo = null;

        if ($intermedios !== []) {
            $archivo = tempnam(sys_get_temp_dir(), 'alexa_chain_');
            if ($archivo === false) {
                return false;
            }

            file_put_contents($archivo, implode("\n", $intermedios));
        }

        try {
            return openssl_x509_checkpurpose($cadena[0], X509_PURPOSE_ANY, [], $archivo) === true;
        } finally {
            if ($archivo !== null) {
                @unlink($archivo);
            }
        }
    }

    /** Un rechazo siempre se registra: es la única traza de un intento de suplantación. */
    private function rechazar(string $motivo): false
    {
        $this->logger->warning('Alexa: petición rechazada — ' . $motivo);

        return false;
    }
}
