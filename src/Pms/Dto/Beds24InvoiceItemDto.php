<?php

declare(strict_types=1);

namespace App\Pms\Dto;

use DateTimeImmutable;
use DateTimeInterface;
use Throwable;

/**
 * DTO fuertemente tipado de un invoiceItem de Beds24. Espejo de
 * App\Message\Dto\Beds24MessageDto.
 *
 * Idempotente ante campos ausentes: el webhook manda los invoiceItems top-level SIN
 * invoiceId/invoiceDate; el endpoint on-demand (GET /bookings/invoices) sí los trae (el
 * MappingStrategy los propaga desde la factura padre a cada item antes de construir el DTO).
 */
final readonly class Beds24InvoiceItemDto
{
    public function __construct(
        public ?string            $id,
        public ?string            $bookingId,
        public ?string            $invoiceId,
        public ?string            $invoiceeId,
        public ?string            $type,
        public ?int               $subType,
        public ?string            $description,
        public ?string            $status,
        public ?string            $qty,
        public ?string            $amount,
        public ?string            $lineTotal,
        public ?string            $vatRate,
        public ?string            $createdBy,
        public ?DateTimeInterface $createTime,
        public ?DateTimeInterface $invoiceDate,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: self::toStringOrNull($data['id'] ?? null),
            bookingId: self::toStringOrNull($data['bookingId'] ?? null),
            invoiceId: self::toStringOrNull($data['invoiceId'] ?? null),
            invoiceeId: self::toStringOrNull($data['invoiceeId'] ?? null),
            type: self::toStringOrNull($data['type'] ?? null),
            subType: isset($data['subType']) && $data['subType'] !== '' ? (int) $data['subType'] : null,
            description: self::toStringOrNull($data['description'] ?? null),
            status: self::toStringOrNull($data['status'] ?? null),
            qty: self::toDecimalStringOrNull($data['qty'] ?? null),
            amount: self::toDecimalStringOrNull($data['amount'] ?? null),
            lineTotal: self::toDecimalStringOrNull($data['lineTotal'] ?? null),
            vatRate: self::toDecimalStringOrNull($data['vatRate'] ?? null),
            createdBy: self::toStringOrNull($data['createdBy'] ?? null),
            createTime: self::toInstanteUtcOrNull($data['createTime'] ?? null),
            invoiceDate: self::toDiaOrNull($data['invoiceDate'] ?? null),
        );
    }

    private static function toStringOrNull(mixed $v): ?string
    {
        if ($v === null) return null;
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    /** Normaliza importes numéricos a string decimal (compatible con columnas Doctrine 'decimal'). */
    private static function toDecimalStringOrNull(mixed $v): ?string
    {
        if ($v === null || $v === '') return null;
        if (!is_numeric($v)) return null;
        return (string) $v;
    }

    /**
     * ⚠️ `createTime` viene en **UTC** y Beds24 no lo dice, igual que `bookingTime`.
     *
     * Comprobado con datos el 08/09/2026: de los cargos con esta fecha, **94** entraron por webhook
     * con `fecha_creacion_beds24` exactamente **299-300 minutos por delante** de su propio
     * `created_at`; los 34 que salen a cero son cargos nuestros, no de Beds24.
     *
     * Aquí se declara el huso real, así que sale un instante correcto. Pasarlo a hora de pared no
     * se hace aquí: depende del establecimiento de la estancia, que un DTO de transporte no conoce.
     * Lo hace `Beds24InvoiceReceivePersister`.
     *
     * ⚠️ **Esto NO vale para `invoiceDate`** — ver abajo. Beds24 usa dos formatos y mezclarlos
     * retrocedería un día las facturas.
     */
    private static function toInstanteUtcOrNull(mixed $v): ?DateTimeInterface
    {
        $s = self::toStringOrNull($v);
        if ($s === null) return null;

        try {
            return new DateTimeImmutable($s, new \DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * ⚠️ `invoiceDate` es un DÍA, no un instante, y la columna que lo recibe es `date`.
     *
     * Por eso **no se le declara huso ni se convierte**: `new DateTimeImmutable('2026-08-31', UTC)`
     * pasado a hora de Lima es el 30 de agosto a las 19:00, y al guardarse en una columna `date`
     * quedaría como **el día anterior**. Una factura fechada un día antes de lo que dice Beds24 es
     * un descuadre contable que nadie relacionaría con zonas horarias.
     *
     * Es la distinción de `docs/ZonasHorarias.md` §2 aplicada dentro del mismo DTO: dos campos que
     * llegan juntos y piden tratamiento opuesto.
     */
    private static function toDiaOrNull(mixed $v): ?DateTimeInterface
    {
        $s = self::toStringOrNull($v);
        if ($s === null) return null;

        try {
            // Se toma sólo la parte de fecha: si algún día llegara con hora, la hora sobra.
            return new DateTimeImmutable(substr($s, 0, 10));
        } catch (Throwable) {
            return null;
        }
    }
}
