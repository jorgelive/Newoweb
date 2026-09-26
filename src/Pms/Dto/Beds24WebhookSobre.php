<?php

declare(strict_types=1);

namespace App\Pms\Dto;

use App\Dto\Lee;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * El paquete entero de un webhook de Beds24, leído una vez: la reserva, sus mensajes y sus líneas
 * de factura, y el instante del evento.
 *
 * Lo leían a mano dos sitios: `Beds24WebhookController` (el instante, para el colchón de 15 s) y
 * `ProcessBeds24WebhookDispatchHandler` (el reparto y la etiqueta de la auditoría). Cada pieza sigue
 * pasando por su propio DTO —`Beds24BookingDto`, `Beds24MessageDto`, `Beds24InvoiceItemDto`—; aquí
 * sólo se decide QUÉ piezas hay, sin interpretarlas.
 *
 * Ver `docs/TiposDeFrontera.md` y `docs/PmsBeds24ReservasSync.md`.
 */
final readonly class Beds24WebhookSobre
{
    /**
     * @param list<array<mixed>> $reservas Cada una, tal cual: la lee `Beds24BookingDto::fromArray()`.
     * @param list<array<mixed>> $mensajes Cada uno, tal cual: lo lee `Beds24MessageDto::fromArray()`.
     * @param list<array<mixed>> $facturas Cada línea, tal cual: la lee `Beds24InvoiceItemDto::fromArray()`.
     */
    public function __construct(
        public bool $traeReserva,
        public bool $traeMensajes,
        public bool $traeFacturas,
        public array $reservas,
        public array $mensajes,
        public array $facturas,
        /** Para la etiqueta de la auditoría: «B24 #id | nombre | [canal] | …». */
        public ?string $reservaId,
        public string $huesped,
        public string $canal,
        public ?int $momento,
    ) {}

    /** @param array<mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $booking = $payload['booking'] ?? null;
        // Para la etiqueta, el nodo tal cual: si llegara como LISTA no se mira dentro —como antes—,
        // y la auditoría dice «#N/A». Las reservas en sí sí se recorren todas (`$reservas`).
        $primera = Lee::mapa($booking);

        return new self(
            // `isset()`: presente y no nulo, que es lo que miraba el reparto.
            traeReserva: isset($payload['booking']),
            traeMensajes: isset($payload['messages']),
            traeFacturas: isset($payload['invoiceItems']),
            reservas: self::conId($booking),
            mensajes: self::conId($payload['messages'] ?? null),
            facturas: self::conId(is_array($payload['invoiceItems'] ?? null) ? array_values($payload['invoiceItems']) : null),
            reservaId: Lee::texto($primera['id'] ?? null),
            huesped: trim((Lee::texto($primera['firstName'] ?? null) ?? '') . ' ' . (Lee::texto($primera['lastName'] ?? null) ?? '')),
            canal: strtoupper(Lee::texto($primera['referer'] ?? null) ?? 'DIRECT'),
            momento: self::momentoDe($payload),
        );
    }

    /**
     * Un nodo que puede ser UNA pieza o una lista de ellas; se queda con las que traen `id`.
     *
     * @return list<array<mixed>>
     */
    private static function conId(mixed $nodo): array
    {
        if (!is_array($nodo)) {
            return [];
        }

        $piezas = array_is_list($nodo) ? $nodo : [$nodo];

        return array_values(array_filter(
            $piezas,
            static fn (mixed $p): bool => is_array($p) && isset($p['id']),
        ));
    }

    /**
     * Cuándo pasó lo que avisa el webhook, en segundos Unix. `null` si no se puede saber.
     *
     * Por prioridad:
     * 1. El ÚLTIMO mensaje, si es del anfitrión: queremos al huésped lo más rápido posible.
     * 2. El `timeStamp` del paquete.
     * 3. Si no lo hay, `modifiedTime` de la reserva, o su `bookingTime`.
     *
     * ⚠️ Las fechas de Beds24 llegan SIN huso («2025-07-30T14:32:00») y son UTC. Parsearlas como
     * locales desplazaba el instante cinco horas, y con la ventana de 10 minutos del controlador
     * ningún evento de reserva se consideraba reciente NUNCA. La del mensaje se parsea sin huso a
     * propósito, como hacía el controlador: trae su propio desplazamiento.
     *
     * @param array<mixed> $payload
     */
    private static function momentoDe(array $payload): ?int
    {
        $mensajes = $payload['messages'] ?? null;
        if (is_array($mensajes) && $mensajes !== []) {
            $ultimo = end($mensajes);
            $hora = is_array($ultimo) && ($ultimo['source'] ?? '') === 'host' ? Lee::texto($ultimo['time'] ?? null) : null;
            if ($hora !== null && $hora !== '' && $hora !== '0') {
                $momento = self::instante($hora, null);
                if ($momento !== null) {
                    return $momento;
                }
            }
        }

        $timeStamp = $payload['timeStamp'] ?? null;
        if (!empty($timeStamp)) {
            $texto = Lee::texto($timeStamp);

            return $texto !== null ? self::instante($texto, new DateTimeZone('UTC')) : null;
        }

        if (isset($payload['booking'])) {
            $reserva = Lee::mapa($payload['booking']);
            $hora = !empty($reserva['modifiedTime']) ? $reserva['modifiedTime'] : ($reserva['bookingTime'] ?? null);
            $texto = Lee::texto($hora);

            return $texto !== null && $texto !== '' && $texto !== '0' ? self::instante($texto, new DateTimeZone('UTC')) : null;
        }

        return null;
    }

    private static function instante(string $hora, ?DateTimeZone $huso): ?int
    {
        try {
            return (new DateTimeImmutable($hora, $huso))->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }
}
