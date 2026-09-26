<?php

declare(strict_types=1);

namespace App\Message\Dto\Meta;

use App\Dto\Lee;

/**
 * Una llamada de voz entrante por WhatsApp: un elemento de `value.calls[]`.
 */
final readonly class MetaLlamada
{
    public function __construct(
        public ?string $id,
        public ?int $timestamp,
    ) {}

    /** @param array<mixed> $llamada */
    public static function fromArray(array $llamada): self
    {
        return new self(
            id: Lee::texto($llamada['id'] ?? null),
            timestamp: Lee::entero($llamada['timestamp'] ?? null),
        );
    }
}
