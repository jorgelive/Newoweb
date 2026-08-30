<?php

declare(strict_types=1);

namespace App\Finanzas\Service\Culqi;

use App\Finanzas\Contract\FinPasarelaClientInterface;
use App\Finanzas\Entity\FinEnlacePago;
use App\Finanzas\Enum\FinPasarela;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Cliente REST de Culqi (`api.culqi.com/v2`).
 *
 * ## El flujo NO es como el de Izipay
 *
 * Con Izipay el servidor abre el cobro (formToken) y el navegador lo cierra hablando con la
 * pasarela. Con Culqi es al revés: el navegador captura la tarjeta y devuelve un **token**,
 * y es **nuestro servidor** el que crea el cargo con ese token (`cobrarConToken()`). La
 * respuesta de ese POST ya dice si el dinero entró; el webhook es un aviso secundario.
 *
 * Eso tiene una consecuencia buena: el camino principal de confirmación es una llamada
 * nuestra autenticada, no un mensaje entrante. No hay nada que falsificar.
 *
 * ## Por qué el webhook no se cree nada
 *
 * La documentación de Culqi **no publica ninguna firma, secreto ni lista de IPs** para sus
 * webhooks (comprobado en su doc de webhooks y en la referencia de API). Un endpoint
 * público sin firma es un endpoint que cualquiera puede llamar: creerse el cuerpo permitiría
 * marcar reservas como pagadas con un `curl`.
 *
 * Por eso `CulqiWebhookController` usa el aviso sólo para sacar un id y llama a
 * `verificarCargo()`, que pregunta a Culqi con nuestra clave secreta. Eso es de fiar
 * independientemente de quién mandó el webhook, y sigue valiendo si mañana Culqi añade firma.
 *
 * @see https://docs.culqi.com/es/documentacion/pagos-online/
 */
final class CulqiClient implements FinPasarelaClientInterface
{
    private const RUTA_CHARGES = '/v2/charges';
    private const RUTA_REFUNDS = '/v2/refunds';

    /**
     * Los tres valores que admite `reason` en la API de devoluciones de Culqi:
     * `duplicado`, `fraudulento`, `solicitud_comprador`.
     *
     * El nuestro es siempre el tercero: quien devuelve aquí es el operador atendiendo a un
     * cliente que lo pidió. Los otros dos existen para casos que no pasan por este panel —un
     * cobro duplicado se resuelve en el Backoffice, y un fraude no lo declara una recepción.
     */
    public const MOTIVO_POR_DEFECTO = 'solicitud_comprador';

    /**
     * Librería JS del **Checkout Custom**.
     *
     * No es la de Checkout v4 (`checkout.culqi.com/js/v4`), que también funciona. Se eligió
     * ésta porque v4 se apoya en globales —`window.Culqi` como singleton y `window.culqi`
     * como callback buscado por nombre— y en una SPA eso obliga a asignar y borrar globales
     * en cada montaje; si se escapa uno, al reentrar en la página se dispara el callback del
     * componente anterior contra un enlace que ya no toca.
     *
     * Checkout Custom es instanciable (`new CulqiCheckout(pk, config)`) y el callback va en
     * la instancia. No es un salto de versión: es quitar estado global compartido.
     */
    private const CHECKOUT_JS = 'https://js.culqi.com/checkout-js';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire('%finanzas.culqi.endpoint%')]
        private readonly string $endpoint,
        #[Autowire('%finanzas.culqi.public_key%')]
        private readonly string $publicKey,
        #[Autowire('%finanzas.culqi.secret_key%')]
        private readonly string $secretKey,
    ) {}

    public function pasarela(): FinPasarela
    {
        return FinPasarela::CULQI;
    }

    public function estaConfigurado(): bool
    {
        return $this->publicKey !== '' && $this->secretKey !== '';
    }

    /**
     * Config para el Checkout del navegador.
     *
     * A diferencia de Izipay, aquí NO se abre nada en la pasarela: son datos estáticos más
     * el importe. Se puede recargar la página las veces que haga falta sin consumir nada.
     */
    public function configuracionPago(FinEnlacePago $enlace): array
    {
        return [
            'publicKey' => $this->publicKey,
            'checkoutJs' => self::CHECKOUT_JS,
            // Céntimos, entero. Culqi cobra en la unidad mínima igual que Lyra: mandar
            // `150.00` en vez de `15000` cobra un sol y medio en lugar de ciento cincuenta.
            'amount' => $enlace->montoTotalCentimos(),
            'currency' => $enlace->getMonedaCodigo() ?? 'PEN',
            'descripcion' => $enlace->getConcepto(),
            'email' => $enlace->getClienteEmail(),
        ];

        // ⚠️ NO se manda `order`, y es a propósito. En Culqi `order` NO es una referencia
        // libre: es el id de una orden creada antes vía `/v2/orders` (formato `ord_...`), y
        // es lo que habilita Yape y pago en efectivo. Nuestro `ordenId` es una cadena
        // nuestra —heredada del `orderId` de Izipay, donde sí es libre—, así que pasarla
        // aquí hace que el Checkout **no abra**: `settings()` la acepta sin rechistar y
        // `open()` falla en silencio. Colisión de nombres entre pasarelas.
        //
        // Sin `order`, el Checkout muestra sólo tarjeta, que es justo lo que soportamos hoy.
        // Nuestro `ordenId` sigue viajando en `metadata` de `cobrarConToken()`, que es donde
        // sirve para conciliar.
    }

    /**
     * Crea el cargo con el token que devolvió el navegador. Es el camino principal.
     *
     * @return array<string, mixed> El objeto `charge` de Culqi.
     * @throws RuntimeException si Culqi rechaza el cobro (tarjeta denegada, fondos, etc.).
     */
    public function cobrarConToken(FinEnlacePago $enlace, string $tokenTarjeta): array
    {
        return $this->peticion('POST', self::RUTA_CHARGES, [
            'amount' => $enlace->montoTotalCentimos(),
            'currency_code' => $enlace->getMonedaCodigo() ?? 'PEN',
            'email' => $enlace->getClienteEmail() ?: 'pagos@openperu.pe',
            'source_id' => $tokenTarjeta,
            'description' => substr($enlace->getConcepto(), 0, 80),
            // Vuelve en el objeto `charge` y en el webhook: es el hilo que ata el cobro de
            // Culqi con nuestra fila sin depender de parsear el `orderId`.
            'metadata' => [
                'enlaceId' => (string) $enlace->getId(),
                'origenTipo' => $enlace->getOrigenTipo()->value,
                'ordenId' => (string) $enlace->getOrdenId(),
            ],
        ]);
    }

    /**
     * Devuelve el dinero de un cargo ya cobrado.
     *
     * ── Se devuelve el NETO, no el total ────────────────────────────────────────
     * El huésped pagó `montoTotal` (neto + 5.5%) y a su documento se le abonó `montoNeto`.
     * Se devuelve **lo que el documento recibió**, y el recargo se queda donde estaba: fue
     * el coste de pagar con tarjeta, anunciado en el propio botón antes de pulsarlo
     * («Incluye 5.5% de comisión»). Devolver el total significaría que la casa paga la
     * comisión de una operación que ya no existe.
     *
     * Y coincide con lo que hace Culqi: su documentación dice que **pasada la fecha de la
     * venta la devolución descuenta su comisión**, o sea que no la reintegra. Devolver el
     * total obligaría a poner esa diferencia de nuestro bolsillo.
     *
     * ── `reason` es un ENUM cerrado ─────────────────────────────────────────────
     * `duplicado`, `fraudulento`, `solicitud_comprador`. No es texto libre — el README de la
     * librería PHP de Culqi pone una frase de ejemplo y engaña.
     *
     * ⚠️ Por eso `$motivo` **NO** alimenta `reason`: se manda siempre `MOTIVO_POR_DEFECTO`.
     * El texto del operador va a `metadata` (que no valida nada) y sobre todo a la nota del
     * asiento del módulo, que es donde se lee. El parámetro se queda en la firma porque lo
     * declara el contrato y otra pasarela puede sí saber usarlo.
     *
     * ⚠️ El importe va en **céntimos**, igual que el cargo (`montoTotalCentimos()`). Mandar
     * `100.00` en vez de `10000` devuelve un sol en lugar de cien, y la API lo acepta.
     *
     * @return array<string, mixed> El objeto `refund` de Culqi.
     * @throws RuntimeException si Culqi rechaza la devolución (fuera de plazo, ya devuelto,
     *                          saldo insuficiente en la cuenta…).
     */
    public function reembolsar(FinEnlacePago $enlace, string $motivo = self::MOTIVO_POR_DEFECTO): array
    {
        $cargoId = $enlace->getTransaccionUuid();

        if ($cargoId === null || $cargoId === '') {
            throw new RuntimeException(
                'Este enlace no guardó el id del cargo de Culqi, así que no se puede devolver '
                . 'desde aquí. Hazlo en el Backoffice de la pasarela.'
            );
        }

        return $this->peticion('POST', self::RUTA_REFUNDS, [
            'amount' => $enlace->montoNetoCentimos(),
            'charge_id' => $cargoId,
            // ⚠️ CONSTANTE, no `$motivo`. `reason` es un enum cerrado de Culqi y el texto del
            // operador lo haría inválido: la devolución se rechazaría con un 502 que no
            // explica nada. Se mandó `$motivo` aquí en la primera versión y era un fallo —
            // el flujo entero no habría funcionado salvo que alguien escribiera por
            // casualidad una de las tres palabras.
            'reason' => self::MOTIVO_POR_DEFECTO,
            'metadata' => [
                'enlaceId' => (string) $enlace->getId(),
                'ordenId' => (string) $enlace->getOrdenId(),
                // Aquí SÍ cabe el texto libre: metadata no valida nada y vuelve en el objeto
                // `refund`, así que el porqué del operador queda también del lado de Culqi
                // para quien concilie desde su Backoffice.
                'motivo' => $motivo !== '' ? mb_substr($motivo, 0, 200) : 'no indicado',
            ],
        ]);
    }

    /**
     * Pregunta a Culqi por un cargo. Es lo ÚNICO en lo que confía el webhook.
     *
     * @return array<string, mixed>|null null si no existe o no se pudo consultar.
     */
    public function verificarCargo(string $idCargo): ?array
    {
        try {
            return $this->peticion('GET', self::RUTA_CHARGES . '/' . rawurlencode($idCargo));
        } catch (RuntimeException $e) {
            $this->logger->warning('[culqi] no se pudo verificar el cargo', [
                'cargo' => $idCargo,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * ¿Este cargo paga ESTE enlace?
     *
     * No basta con que el cargo exista y esté pagado: se comprueba **importe y moneda**
     * contra el enlace. Sin eso, un atacante podría mandarnos por webhook el id de un cargo
     * real de S/1 —suyo, legítimo— y saldar una reserva de S/2000. El webhook trae un id
     * que no controlamos, así que el importe hay que verificarlo siempre.
     *
     * `object === 'charge'` es la señal de éxito: Culqi sólo materializa el objeto cargo
     * cuando el cobro se autorizó; un rechazo devuelve un objeto de error, no un cargo.
     *
     * ⚠️ Pendiente de confirmar con el primer cobro real de test: si `outcome.type` trae
     * algún valor de rechazo con el objeto ya creado, hay que añadirlo aquí. Se deja
     * registrado en el log para poder verlo.
     *
     * @param array<string, mixed> $cargo
     */
    public function cargoPagaElEnlace(array $cargo, FinEnlacePago $enlace): bool
    {
        if (($cargo['object'] ?? null) !== 'charge') {
            return false;
        }

        $importeOk = (int) ($cargo['amount'] ?? 0) === $enlace->montoTotalCentimos();
        $monedaOk = ($cargo['currency_code'] ?? null) === ($enlace->getMonedaCodigo() ?? 'PEN');

        if (!$importeOk || !$monedaOk) {
            $this->logger->error('[culqi] cargo que NO corresponde al enlace; se rechaza', [
                'enlace' => (string) $enlace->getId(),
                'esperado' => $enlace->montoTotalCentimos() . ' ' . $enlace->getMonedaCodigo(),
                'recibido' => ($cargo['amount'] ?? '?') . ' ' . ($cargo['currency_code'] ?? '?'),
            ]);

            return false;
        }

        $this->logger->info('[culqi] cargo verificado', [
            'enlace' => (string) $enlace->getId(),
            'outcome' => $cargo['outcome']['type'] ?? null,
        ]);

        return true;
    }

    /**
     * Traduce el objeto `charge` de Culqi a la forma que espera `FinEnlacePagoService`.
     *
     * @param array<string, mixed> $cargo El cargo tal cual lo devuelve Culqi.
     *
     * @return array<string, mixed> La forma común que consume Finanzas.
     */
    public function comoRespuestaNormalizada(array $cargo): array
    {
        $tarjeta = $cargo['source'] ?? [];

        return [
            'orderStatus' => 'PAID',
            'transactions' => [[
                'uuid' => $cargo['id'] ?? null,
                'transactionDetails' => [
                    'cardDetails' => [
                        'effectiveBrand' => $tarjeta['iin']['card_brand'] ?? null,
                        'pan' => isset($tarjeta['last_four']) ? '****' . $tarjeta['last_four'] : null,
                        'authorizationResponse' => [
                            'authorizationNumber' => $cargo['reference_code'] ?? null,
                        ],
                    ],
                ],
            ]],
            // El objeto crudo entero, que es lo que de verdad se consulta meses después.
            'culqi' => $cargo,
        ];
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function peticion(string $metodo, string $ruta, ?array $payload = null): array
    {
        if (!$this->estaConfigurado()) {
            throw new RuntimeException(
                'Culqi no está configurado: define CULQI_PUBLIC_KEY y CULQI_SECRET_KEY en .env.local.'
            );
        }

        $opciones = [
            // Bearer con la llave PRIVADA. La pública sólo sirve para identificar la cuenta
            // en el navegador y no autoriza ninguna operación.
            'auth_bearer' => $this->secretKey,
            'timeout' => 20,
        ];

        if ($payload !== null) {
            $opciones['json'] = $payload;
        }

        try {
            $respuesta = $this->httpClient->request($metodo, rtrim($this->endpoint, '/') . $ruta, $opciones);
            $codigo = $respuesta->getStatusCode();
            /** @var array<string, mixed> $datos */
            $datos = $respuesta->toArray(false);
        } catch (HttpExceptionInterface $e) {
            throw new RuntimeException('No se pudo contactar con Culqi: ' . $e->getMessage(), 0, $e);
        }

        // Culqi SÍ usa códigos HTTP correctamente (a diferencia de Lyra, que responde 200
        // aunque rechace), y en el error devuelve `object: "error"` con `user_message`.
        if ($codigo >= 400 || ($datos['object'] ?? null) === 'error') {
            $detalle = $datos['user_message'] ?? $datos['merchant_message'] ?? 'error desconocido';

            $this->logger->error('[culqi] respuesta de error', [
                'ruta' => $ruta,
                'codigo' => $codigo,
                'respuesta' => $datos,
            ]);

            throw new RuntimeException('Culqi rechazó la operación: ' . $detalle);
        }

        return $datos;
    }
}
