<?php

declare(strict_types=1);

namespace App\Message\Dto\Meta;

/**
 * Quién escribe: `value.contacts[0]` del webhook de Meta.
 */
final readonly class MetaContacto
{
    public function __construct(
        /** El número en formato de Meta, sin «+». Es lo que identifica al huésped. */
        public ?string $waId,
        /** El nombre de su perfil de WhatsApp, el que él se puso. */
        public ?string $nombre,
    ) {}

    /** @param array<mixed> $contacto */
    public static function fromArray(array $contacto): self
    {
        return new self(
            waId: LeeMeta::texto($contacto['wa_id'] ?? null),
            nombre: LeeMeta::texto(LeeMeta::mapa($contacto['profile'] ?? null)['name'] ?? null),
        );
    }
}
