<?php

declare(strict_types=1);

namespace App\Message\Dto\Meta;

use App\Dto\Lee;

/**
 * Un `changes[].value` del webhook de Meta: los mensajes, estados y llamadas que trae, y de quién.
 */
final readonly class MetaCambio
{
    /**
     * @param list<MetaMensajeEntrante> $mensajes
     * @param list<MetaEstado>          $estados
     * @param list<MetaLlamada>         $llamadas
     */
    public function __construct(
        /**
         * El primer `contacts[]`. `null` si no vino ninguno.
         *
         * ⚠️ Meta manda los mensajes SIEMPRE con su contacto; los estados, nunca. El código de antes
         * sólo procesaba mensajes si `contacts` existía, y leía `contacts[0]` sin comprobar que la
         * lista trajera algo. Ver `WhatsappMetaWebhookMessageFastTrackService::procesarSobre()`.
         */
        public ?MetaContacto $contacto,
        public array $mensajes,
        public array $estados,
        public array $llamadas,
    ) {}

    /** @param array<mixed> $valor */
    public static function fromArray(array $valor): self
    {
        $contactos = Lee::listaDeMapas($valor['contacts'] ?? null);

        return new self(
            contacto: $contactos !== [] ? MetaContacto::fromArray($contactos[0]) : null,
            mensajes: array_map(MetaMensajeEntrante::fromArray(...), Lee::listaDeMapas($valor['messages'] ?? null)),
            estados: array_map(MetaEstado::fromArray(...), Lee::listaDeMapas($valor['statuses'] ?? null)),
            llamadas: array_map(MetaLlamada::fromArray(...), Lee::listaDeMapas($valor['calls'] ?? null)),
        );
    }
}
