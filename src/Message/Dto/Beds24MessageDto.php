<?php

declare(strict_types=1);

namespace App\Message\Dto;

use DateTimeImmutable;
use DateTimeInterface;
use Throwable;

final readonly class Beds24MessageDto
{
    public function __construct(
            public ?string            $id,
            public ?string            $authorOwnerId,
            public ?string            $bookingId,
            public ?string            $roomId,
            public ?string            $propertyId,
            public ?DateTimeInterface $time,
            public bool               $read,
            public ?string            $message,
            public ?string            $source,
            // Nuevos campos para adjuntos
            public ?string            $attachment,
            public ?string            $attachmentName,
            public ?string            $attachmentMimeType
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
                id: self::toStringOrNull($data['id'] ?? null),
                authorOwnerId: self::toStringOrNull($data['authorOwnerId'] ?? null),
                bookingId: self::toStringOrNull($data['bookingId'] ?? null),
                roomId: self::toStringOrNull($data['roomId'] ?? null),
                propertyId: self::toStringOrNull($data['propertyId'] ?? null),
                time: self::toDateTimeOrNull($data['time'] ?? null),
                read: (bool) ($data['read'] ?? false),
                message: self::toStringOrNull($data['message'] ?? null),
                source: self::toStringOrNull($data['source'] ?? null),

                // Adjuntos
                attachment: self::toStringOrNull($data['attachment'] ?? null),
                attachmentName: self::toStringOrNull($data['attachmentName'] ?? null),
                attachmentMimeType: self::toStringOrNull($data['attachmentMimeType'] ?? null)
        );
    }

    private static function toStringOrNull(mixed $v): ?string
    {
        if ($v === null) return null;
        $s = trim((string) $v);
        return $s === '' ? null : $s;
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