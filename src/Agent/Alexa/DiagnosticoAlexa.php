<?php

declare(strict_types=1);

namespace App\Agent\Alexa;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Una línea de log por petición legítima de Alexa, con todo lo que Amazon mandó menos los tokens.
 *
 * Existe porque con las líneas de siempre no se podía ni contestar «¿cuántos perfiles de voz
 * hay?». La identidad y la pregunta iban en líneas separadas sin nada que las uniera —se
 * emparejaban por orden, que con dos Echos a la vez falla—, no constaba desde qué Echo se hablaba
 * y `personId` es un hash opaco: dos distintos no dicen si son dos personas o una que Alexa
 * confundió. Ver docs/AgentVoz.md §5.2.
 *
 * El **nombre del perfil de voz** sale de la Customer Profile API de Amazon, y sólo si la skill
 * tiene el permiso «Given Name» a nivel de persona y alguien lo concedió en la app de Alexa. Sin
 * permiso la API contesta 403 y la línea lo dice (`voz_nombre_http=403`): es la pista de que
 * falta ese paso, no un fallo.
 *
 * ⚠️ **Nunca tumba ni retrasa la respuesta de verdad.** Alexa corta a los ~8 s (§6) y el agente ya
 * se come casi todos: la consulta del nombre tiene 1,5 s de tope, y cualquier excepción aquí se
 * traga. Es diagnóstico; si falla, se pierde una línea, no una respuesta.
 */
final readonly class DiagnosticoAlexa
{
    private const float TIEMPO_MAXIMO_NOMBRE = 1.5;

    /** Nunca al log: dan acceso a las APIs de Amazon en nombre del cliente. */
    private const array CLAVES_SECRETAS = ['apiAccessToken', 'accessToken', 'consentToken'];

    /** Los únicos hosts a los que se manda el token de acceso: las tres regiones de la API de Alexa. */
    private const array ENDPOINTS_DE_AMAZON = [
        'https://api.amazonalexa.com',
        'https://api.eu.amazonalexa.com',
        'https://api.fe.amazonalexa.com',
    ];

    public function __construct(
        private HttpClientInterface $http,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param array<mixed> $sobre El JSON tal cual llegó: va entero —saneado— al contexto de la línea.
     */
    public function registrar(array $sobre, PeticionAlexa $alexa): void
    {
        try {
            $nombre = $alexa->persona !== null ? $this->nombreDeLaVoz($alexa) : ['http' => null, 'nombre' => null];

            $this->logger->info(sprintf(
                'Alexa: petición %s%s sesión=%s nueva=%s idioma=%s persona=%s voz_nombre=%s voz_nombre_http=%s dispositivo=%s cuenta=%s',
                $alexa->tipo,
                $alexa->intent !== null ? ' (' . $alexa->intent . ')' : '',
                self::corto($alexa->sesion),
                $alexa->sesionNueva ? 'sí' : 'no',
                $alexa->idioma ?? '?',
                $alexa->persona ?? '(voz no reconocida)',
                $nombre['nombre'] ?? '—',
                $nombre['http'] ?? '—',
                $alexa->dispositivo ?? '(sin id)',
                $alexa->usuarioAlexa ?? '(sin id)',
            ), ['sobre' => self::sanear($sobre)]);
        } catch (Throwable $e) {
            $this->logger->warning('Alexa: no se pudo registrar el diagnóstico: ' . $e->getMessage());
        }
    }

    /**
     * El nombre de pila del perfil de voz que Alexa reconoció.
     *
     * `http` es el código que devolvió Amazon: 200 con nombre, 204 si el perfil no tiene nombre,
     * 403 si falta el permiso. `null` si ni siquiera se pudo preguntar (sin endpoint o sin token).
     *
     * @return array{http: int|string|null, nombre: string|null}
     */
    private function nombreDeLaVoz(PeticionAlexa $alexa): array
    {
        $endpoint = $alexa->apiEndpoint;
        $token = $alexa->apiToken;

        if ($endpoint === null || $token === null) {
            return ['http' => null, 'nombre' => null];
        }

        // El token va sólo a Amazon. La firma de la petición ya garantiza que el endpoint lo puso
        // Amazon, así que esto es defensa en profundidad: el día que la firma se relaje —como pasó
        // con el `appSecret` vacío de Meta—, el token de acceso no puede salir hacia otro host.
        if (!in_array(rtrim($endpoint, '/'), self::ENDPOINTS_DE_AMAZON, true)) {
            return ['http' => 'endpoint no es de Amazon', 'nombre' => null];
        }

        try {
            $respuesta = $this->http->request('GET', rtrim($endpoint, '/') . '/v2/persons/~current/profile/givenName', [
                'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
                'timeout' => self::TIEMPO_MAXIMO_NOMBRE,
                'max_duration' => self::TIEMPO_MAXIMO_NOMBRE,
            ]);

            $codigo = $respuesta->getStatusCode();
            if ($codigo !== 200) {
                return ['http' => $codigo, 'nombre' => null];
            }

            // Amazon lo devuelve como cadena JSON: `"Jorge"`, con comillas.
            $nombre = json_decode($respuesta->getContent(false), true);

            return ['http' => 200, 'nombre' => is_string($nombre) && $nombre !== '' ? $nombre : null];
        } catch (Throwable $e) {
            return ['http' => 'error: ' . mb_substr($e->getMessage(), 0, 60), 'nombre' => null];
        }
    }

    /**
     * El sobre sin tokens y con los valores dictados tachados como en el resto del log.
     *
     * @param array<mixed> $sobre
     * @return array<mixed>
     */
    public static function sanear(array $sobre): array
    {
        $limpio = [];
        foreach ($sobre as $clave => $valor) {
            if (is_string($clave) && in_array($clave, self::CLAVES_SECRETAS, true)) {
                $limpio[$clave] = '(omitido)';
            } elseif (($clave === 'value' || $clave === 'texto') && is_string($valor)) {
                // Lo que se dicta en un slot puede ser un código de puerta: misma regla que
                // AlexaController::sinSecretos().
                //
                // 🔥 Y `texto`: es la clave del historial que Alexa nos DEVUELVE en
                // `session.attributes.historial`, con la respuesta del turno anterior tal cual se
                // dijo —el código de la caja fuerte incluido—. Sólo se tachaba `value`, así que el
                // código salía en claro en el log del turno siguiente (revisión del 16/10/2026).
                $limpio[$clave] = preg_replace('/\d{4,}/u', '····', $valor) ?? $valor;
            } elseif (is_array($valor)) {
                $limpio[$clave] = self::sanear($valor);
            } else {
                $limpio[$clave] = $valor;
            }
        }

        return $limpio;
    }

    /** Lo bastante para cruzar líneas de la misma sesión sin llenar el log de hashes. */
    public static function corto(?string $id): string
    {
        return $id === null || $id === '' ? '(sin id)' : '…' . substr($id, -12);
    }
}
