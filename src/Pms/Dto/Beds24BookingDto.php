<?php

declare(strict_types=1);

namespace App\Pms\Dto;

use DateTimeImmutable;
use DateTimeInterface;

final class Beds24BookingDto
{
    public function __construct(
        public readonly ?int $id,
        public readonly ?int $masterId,    // El ID calculado (Padre)
        public readonly ?int $propertyId,
        public readonly ?int $roomId,
        public readonly ?string $status,
        public readonly ?string $subStatus,
        public readonly ?string $arrival,
        public readonly ?string $departure,
        public readonly ?int $numAdult,
        public readonly ?int $numChild,
        public readonly ?string $firstName,
        public readonly ?string $lastName,
        public readonly ?string $email,
        public readonly ?string $phone,
        public readonly ?string $mobile,
        public readonly ?string $notes,
        public readonly ?string $comments,
        public readonly ?string $arrivalTime,
        /**
         * ⚠️ **`country2`, NUNCA `country`. Beds24 manda LAS DOS y no son lo mismo.**
         *
         * Payload real (auditoría del 15/08/2026):
         *
         *     "country": "pe",   ← minúsculas, ISO2 en crudo
         *     "country2": "PE"   ← MAYÚSCULAS, la canónica
         *
         * Este DTO expone sólo `country2` a propósito: los identificadores de `MaestroPais` son
         * mayúsculas (`PE`, `ES`, `DE`), así que casa directo con `find()` sin normalizar nada.
         *
         * 🚫 **No lo «corrijas» a `country`.** Ya pasó: `BookingPullPersister` leía
         * `$dto->country` —propiedad que no existe aquí—, el `??` devolvía cadena vacía y la
         * inferencia de idioma por país llevaba muerta desde que se escribió, sin dar un solo
         * error. Se arregló el 15/08/2026. Si vuelves a ver `->country` en algún sitio, es el
         * fallo, no una mejora.
         */
        public readonly ?string $country2,
        public readonly ?string $lang,
        public readonly ?string $channel,
        public readonly ?string $apiReference,
        public readonly ?string $rateDescription,
        public readonly mixed $price,
        public readonly mixed $commission,
        public readonly ?DateTimeInterface $bookingTime,
        public readonly ?DateTimeInterface $modifiedTime,
        public readonly ?string $custom1 = null,
        public readonly ?string $custom2 = null,
        /**
         * Estructura cruda del grupo, para llegar a `master` o a `ids`.
         *
         * ⚠️ Estaba declarado ANTES de los obligatorios. PHP marca eso como obsoleto desde la
         * 8.0 —«optional parameter declared before required parameter»— y además el `= null` no
         * servía de nada: quien construyera por posición tenía que pasarlo igual. Se mueve al
         * final, donde el valor por defecto sí significa algo. Es seguro porque el único sitio
         * que lo construye, `fromArray()`, usa argumentos nombrados.
         *
         * @var array<string, mixed>|null
         */
        public readonly ?array $bookingGroup = null,
    ) {}

    /** @param array<string, mixed> $booking Una reserva tal como la devuelve la API de Beds24. */
    public static function fromArray(array $booking): self
    {
        // 1. Capturamos el grupo entero (si viene)
        $bookingGroup = isset($booking['bookingGroup']) && is_array($booking['bookingGroup'])
            ? $booking['bookingGroup']
            : [];

        // 2. Lógica "Cascada" para el Master ID
        // Prioridad A: El 'master' dentro del grupo (API v2 estándar)
        // Prioridad B: El 'masterId' en la raíz (API v1 / Legacy)
        $rawMasterId = $bookingGroup['master'] ?? $booking['masterId'] ?? null;

        $numAdult = isset($booking['numAdult']) ? (int) $booking['numAdult'] : 0;
        $numChild = isset($booking['numChild']) ? (int) $booking['numChild'] : 0;

        return new self(
            id: self::toIntOrNull($booking['id'] ?? null),
            masterId: self::toIntOrNull($rawMasterId), // ✅ Aquí va el ID limpio para el Persister
            propertyId: self::toIntOrNull($booking['propertyId'] ?? null),
            roomId: self::toIntOrNull($booking['roomId'] ?? null),
            bookingGroup: $bookingGroup,
            status: self::toStringOrNull($booking['status'] ?? null),
            subStatus: self::toStringOrNull($booking['subStatus'] ?? null),
            arrival: self::toStringOrNull($booking['arrival'] ?? null),
            departure: self::toStringOrNull($booking['departure'] ?? null),
            numAdult: $numAdult,
            numChild: $numChild,
            firstName: self::toStringOrNull($booking['firstName'] ?? null),
            lastName: self::toStringOrNull($booking['lastName'] ?? null),
            email: self::toStringOrNull($booking['email'] ?? null),
            phone: self::toStringOrNull($booking['phone'] ?? null),
            mobile: self::toStringOrNull($booking['mobile'] ?? null),
            notes: self::toStringOrNull($booking['notes'] ?? null),
            comments: self::toStringOrNull($booking['comments'] ?? null),
            arrivalTime: self::toStringOrNull($booking['arrivalTime'] ?? null),
            country2: self::toStringOrNull($booking['country2'] ?? null),
            lang: self::toStringOrNull($booking['lang'] ?? null),
            channel: self::toStringOrNull($booking['channel'] ?? null),
            apiReference: self::toStringOrNull($booking['apiReference'] ?? null),
            rateDescription: self::toStringOrNull($booking['rateDescription'] ?? null),
            price: $booking['price'] ?? null,
            commission: $booking['commission'] ?? null,
            bookingTime: self::toDateTimeOrNull($booking['bookingTime'] ?? null),
            modifiedTime: self::toDateTimeOrNull($booking['modifiedTime'] ?? null),
            custom1: self::toStringOrNull($booking['custom1'] ?? null),
            custom2: self::toStringOrNull($booking['custom2'] ?? null),
        );
    }

    private static function toStringOrNull(mixed $v): ?string
    {
        if ($v === null) return null;
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    private static function toIntOrNull(mixed $v): ?int
    {
        if ($v === null || $v === '') return null;
        if (is_int($v)) return $v;
        if (is_numeric($v)) return (int) $v;
        return null;
    }

    private static function toDateTimeOrNull(mixed $v): ?DateTimeInterface
    {
        $s = self::toStringOrNull($v);
        if ($s === null) return null;

        try {
            return new DateTimeImmutable($s);
        } catch (\Throwable) {
            return null;
        }
    }
}