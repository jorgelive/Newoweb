<?php

declare(strict_types=1);

namespace App\Finanzas\Service\Izipay;

use App\Finanzas\Contract\FinPasarelaClientInterface;
use App\Finanzas\Entity\FinEnlacePago;
use App\Finanzas\Enum\FinPasarela;
use JsonException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Cliente REST de Izipay (plataforma Lyra, `api.micuentaweb.pe`).
 *
 * Cubre lo justo para el formulario embebido: pedir el `formToken` de una transacción y
 * validar la firma de lo que la pasarela nos devuelve. El resto de la conversación ocurre
 * entre el navegador del cliente y la pasarela.
 *
 * ## Las tres claves, que NO son intercambiables
 *
 * Izipay entrega tres credenciales por entorno y confundirlas da errores que no se parecen
 * a su causa:
 *
 * | Clave              | Dónde vive | Para qué |
 * |--------------------|-----------|----------|
 * | `password`         | Servidor  | Basic auth de la API **y** firma de los webhooks (IPN) |
 * | `publicKey`        | Navegador | Inicializa la librería JS. Es pública, puede ir en el HTML |
 * | `hmacSha256Key`    | Servidor  | Firma del retorno al NAVEGADOR (no del IPN) |
 *
 * La trampa de verdad está en la firma: **el IPN se firma con `password` y el retorno al
 * navegador con `hmacSha256Key`**. El propio POST dice cuál se usó en `kr-hash-key`, y por
 * eso `validarFirma()` lo lee en vez de asumir. Validar un IPN con la clave HMAC falla
 * siempre, y la tentación entonces es "desactivar la validación un rato" — que es
 * exactamente cómo se acepta un cobro falsificado.
 *
 * ## 🅿️ PARADA (stalled) — 28/08/2026
 *
 * **Esta pasarela no está en uso y no se toca.** `FINANZAS_PASARELA_POR_DEFECTO=culqi`, e
 * Izipay exige S/200 000 de venta acumulada para habilitar enlaces de pago. El conector se
 * conserva entero y compilando —rehacerlo desde cero costaría mucho más que mantenerlo— pero
 * **no se le arreglan huecos ni se refactoriza** hasta que se implemente de verdad.
 *
 * Los huecos ya detectados están apuntados, con su verificación, en `docs/Pendientes.md`
 * («Izipay: PARADA hasta que se implemente»). Si encuentras otro, va ahí: no aquí.
 *
 * @see https://developers.izipay.pe/api/
 */
final class IzipayClient implements FinPasarelaClientInterface
{
    /**
     * Endpoint de creación de transacción. Devuelve el `formToken` que consume la librería
     * JS para pintar el formulario.
     */
    private const RUTA_CREATE_PAYMENT = '/api-payment/V4/Charge/CreatePayment';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire('%finanzas.izipay.endpoint%')]
        private readonly string $endpoint,
        #[Autowire('%finanzas.izipay.username%')]
        private readonly string $username,
        #[Autowire('%finanzas.izipay.password%')]
        private readonly string $password,
        #[Autowire('%finanzas.izipay.hmac_key%')]
        private readonly string $hmacKey,
        #[Autowire('%finanzas.izipay.public_key%')]
        private readonly string $publicKey,
        // Host de los assets de la librería JS. Va aquí y no en el controlador para que
        // `configuracionPago()` devuelva TODO lo que el navegador necesita de una vez.
        #[Autowire('%finanzas.izipay.static_url%')]
        private readonly string $staticUrl,
    ) {}

    public function pasarela(): FinPasarela
    {
        return FinPasarela::IZIPAY;
    }

    /** Clave pública para el navegador. La página de pago la sirve en el JSON del enlace. */
    public function clavePublica(): string
    {
        return $this->publicKey;
    }

    public function estaConfigurado(): bool
    {
        return $this->username !== '' && $this->password !== '';
    }

    /**
     * Config para el navegador. Ojo: **abre un intento de cobro**, porque el formToken de
     * Lyra es de un solo uso y caduca en minutos. No se cachea.
     */
    public function configuracionPago(FinEnlacePago $enlace): array
    {
        return [
            'formToken' => $this->crearFormToken($enlace),
            'publicKey' => $this->publicKey,
            'staticUrl' => $this->staticUrl,
        ];
    }

    /**
     * Pide a Izipay el `formToken` de un intento de cobro sobre este enlace.
     *
     * Se llama en CADA carga de la página de pago, no una vez al crear el enlace: el
     * formToken caduca en minutos y es de un solo uso. Guardarlo en la fila daría un
     * enlace que funciona la primera vez y falla al reintentar — el fallo más difícil de
     * reproducir de todo este flujo, porque en el navegador del que lo probó funcionaba.
     *
     * @throws RuntimeException si la pasarela no devuelve un formToken utilizable.
     */
    public function crearFormToken(FinEnlacePago $enlace): string
    {
        $payload = [
            // Céntimos, entero. La conversión vive en la entidad: ver `montoTotalCentimos()`.
            'amount' => $enlace->montoTotalCentimos(),
            'currency' => $enlace->getMonedaCodigo() ?? 'PEN',
            'orderId' => $enlace->getOrdenId(),
            'customer' => array_filter([
                'email' => $enlace->getClienteEmail(),
                'reference' => $enlace->getOrigenReferencia(),
                'billingDetails' => array_filter([
                    'firstName' => $enlace->getClienteNombre(),
                    // Desde el 31/08/2026 el enlace guarda el apellido aparte. Se manda aquí
                    // también: dejarlo sólo en Culqi sería que las dos pasarelas enseñaran al
                    // mismo cliente de dos formas distintas.
                    'lastName' => $enlace->getClienteApellido(),
                    'phoneNumber' => $enlace->getClienteTelefono(),
                ]),
            ]),
            // Vuelve intacto en el `kr-answer` del webhook. Es el hilo que ata la respuesta
            // de la pasarela con nuestra fila sin depender de parsear el `orderId`.
            'metadata' => [
                'enlaceId' => (string) $enlace->getId(),
                'origenTipo' => $enlace->getOrigenTipo()->value,
            ],
        ];

        $respuesta = $this->post(self::RUTA_CREATE_PAYMENT, $payload);

        $formToken = $respuesta['answer']['formToken'] ?? null;

        if (!is_string($formToken) || $formToken === '') {
            $this->logger->error('[izipay] CreatePayment sin formToken', [
                'enlace' => (string) $enlace->getId(),
                'respuesta' => $respuesta,
            ]);

            throw new RuntimeException('Izipay no devolvió un formToken para este cobro.');
        }

        return $formToken;
    }

    /**
     * 🅿️ NO IMPLEMENTADO — Izipay está parada (ver la cabecera de la clase).
     *
     * ── Por qué el método existe estando vacío ──────────────────────────────────
     * Porque el contrato manda. `reembolsar()` se declara en `FinPasarelaClientInterface`, y
     * se declaró ahí **a sabiendas** de que Izipay tendría que implementarlo: aflojar el
     * contrato a una capacidad opcional habría escondido este hueco detrás de un `instanceof`
     * que nadie mira. Así el hueco tiene nombre, sale en el editor, y el día que Izipay se
     * habilite la lista de lo que falta es la lista de métodos que lanzan.
     *
     * No contradice el «no se toca lo parado»: eso prohíbe **arreglarle huecos**, no dejarlo
     * compilando, que es justo lo que la cabecera dice que se hace.
     *
     * ── Por qué LANZA y no devuelve un array vacío ──────────────────────────────
     * Es un método de dinero. Si devolviera `[]`, `FinEnlacePagoService::reembolsar()` daría
     * la devolución por buena, marcaría el enlace `REEMBOLSADO` y pondría el cobro a cero —
     * sobre un dinero que sigue en la cuenta del cliente. Nadie lo descubriría hasta que
     * alguien reclamase. Un fallo ruidoso aquí es infinitamente más barato.
     *
     * ── Y por qué no se escribe ahora ───────────────────────────────────────────
     * No hay un solo enlace emitido por Izipay, así que no existe ningún cargo suyo que
     * devolver: el método es inalcanzable hoy. Y escribirlo contra la API de Lyra sin poder
     * probarlo —sin cuenta habilitada— daría código que parece hecho y nadie ha visto
     * funcionar, que es peor que este `throw`.
     *
     * Cuando toque: Lyra expone la devolución en su API de gestión de transacciones, y hace
     * falta el `uuid` de la transacción, que ya se guarda en `transaccionUuid`.
     */
    public function reembolsar(FinEnlacePago $enlace, string $motivo = ''): array
    {
        throw new RuntimeException(
            'Izipay no admite devoluciones desde el sistema: la integración está parada y sin '
            . 'habilitar. Si este cobro salió por Izipay, devuélvelo en su Backoffice.'
        );
    }

    /**
     * ¿La firma del POST recibido es auténtica?
     *
     * Se firma el `kr-answer` **crudo**, tal como llegó. Decodificarlo y volver a
     * serializarlo cambia el espaciado y el escapado de las barras y el HMAC deja de
     * cuadrar: por eso este método recibe el array del POST y no un payload ya parseado.
     *
     * @param array<string, mixed> $post Campos del formulario recibido (`$request->request->all()`).
     */
    public function validarFirma(array $post): bool
    {
        $answer = $post['kr-answer'] ?? null;
        $hash = $post['kr-hash'] ?? null;

        if (!is_string($answer) || !is_string($hash) || $answer === '' || $hash === '') {
            return false;
        }

        // `password` en los IPN servidor-a-servidor; `sha256_hmac` en el retorno al navegador.
        $clave = ($post['kr-hash-key'] ?? 'password') === 'sha256_hmac'
            ? $this->hmacKey
            : $this->password;

        if ($clave === '') {
            $this->logger->error('[izipay] firma no verificable: falta la clave', [
                'kr-hash-key' => $post['kr-hash-key'] ?? null,
            ]);

            return false;
        }

        // hash_equals y no ===: la comparación de firmas se hace en tiempo constante.
        return hash_equals(hash_hmac('sha256', $answer, $clave), $hash);
    }

    /**
     * Decodifica el `kr-answer` ya validado.
     *
     * @return array<string, mixed>
     * @throws RuntimeException si no es JSON.
     */
    public function decodificarRespuesta(string $answer): array
    {
        try {
            /** @var array<string, mixed> $datos */
            $datos = json_decode($answer, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('El kr-answer de Izipay no es JSON válido.', 0, $e);
        }

        return $datos;
    }

    /**
     * ¿La respuesta describe un cobro efectivamente realizado?
     *
     * `orderStatus` es el campo que manda, no `transactions[0].status`: una orden puede
     * tener varios intentos y sólo el estado de la orden resume si el dinero entró.
     * `PAID` es el único valor que cuenta como cobrado; `RUNNING` y `UNPAID` no lo son.
     *
     * @param array<string, mixed> $respuesta
     */
    public function esPagoExitoso(array $respuesta): bool
    {
        return ($respuesta['orderStatus'] ?? null) === 'PAID';
    }

    /**
     * POST autenticado contra la API V4.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function post(string $ruta, array $payload): array
    {
        if (!$this->estaConfigurado()) {
            throw new RuntimeException(
                'Izipay no está configurado: define IZIPAY_USERNAME e IZIPAY_PASSWORD en .env.local.'
            );
        }

        try {
            $respuesta = $this->httpClient->request('POST', rtrim($this->endpoint, '/') . $ruta, [
                'auth_basic' => [$this->username, $this->password],
                'json' => $payload,
                'timeout' => 20,
            ]);

            /** @var array<string, mixed> $datos */
            $datos = $respuesta->toArray(false);
        } catch (HttpExceptionInterface $e) {
            $this->logger->error('[izipay] fallo de transporte', ['ruta' => $ruta, 'error' => $e->getMessage()]);

            throw new RuntimeException('No se pudo contactar con Izipay: ' . $e->getMessage(), 0, $e);
        }

        // La API responde 200 incluso cuando rechaza: el veredicto está en `status`, no en
        // el código HTTP. Comprobar sólo el 200 daría por buena una respuesta de error.
        if (($datos['status'] ?? null) !== 'SUCCESS') {
            $detalle = $datos['answer']['errorMessage']
                ?? $datos['answer']['detailedErrorMessage']
                ?? 'error desconocido';

            $this->logger->error('[izipay] respuesta de error', ['ruta' => $ruta, 'respuesta' => $datos]);

            throw new RuntimeException('Izipay rechazó la petición: ' . $detalle);
        }

        return $datos;
    }
}
