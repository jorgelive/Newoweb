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
            createTime: self::toDateTimeOrNull($data['createTime'] ?? null),
            invoiceDate: self::toDateTimeOrNull($data['invoiceDate'] ?? null),
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

    private static function toDateTimeOrNull(mixed $v): ?DateTimeInterface
    {
        $s = self::toStringOrNull($v);
        if ($s === null) return null;

        try {
            return new DateTimeImmutable($s);
        } catch (Throwable) {
            return null;
        }
    }
}
