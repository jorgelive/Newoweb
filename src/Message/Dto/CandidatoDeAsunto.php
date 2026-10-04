<?php

declare(strict_types=1);

namespace App\Message\Dto;

/**
 * Un asunto que podría ser el de una conversación: lo propone un dominio, lo pinta el chat.
 *
 * La etiqueta la redacta el dominio —qué enseñar de una reserva o de un viaje lo sabe él— y el
 * núcleo la transporta. `motivo` dice por qué sube arriba («mismo prefijo»), también en palabras
 * del dominio: el chat no interpreta nada de esto.
 */
final readonly class CandidatoDeAsunto
{
    public function __construct(
        public string $contextType,
        public string $contextId,
        public string $etiqueta,
        /** Por qué es probable, o `null` si sólo es de estos días. Los que lo llevan van primero. */
        public ?string $motivo = null,
    ) {}

    /** @return array{contextType: string, contextId: string, etiqueta: string, motivo: ?string} */
    public function comoArray(): array
    {
        return [
            'contextType' => $this->contextType,
            'contextId' => $this->contextId,
            'etiqueta' => $this->etiqueta,
            'motivo' => $this->motivo,
        ];
    }
}
