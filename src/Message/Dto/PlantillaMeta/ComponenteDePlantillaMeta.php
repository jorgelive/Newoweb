<?php

declare(strict_types=1);

namespace App\Message\Dto\PlantillaMeta;

use App\Dto\Lee;

/**
 * Un componente de una plantilla de Meta: `HEADER`, `BODY`, `FOOTER` o `BUTTONS`.
 *
 * Meta los manda como una lista plana con un `type` cada uno, y cada tipo usa campos distintos:
 * `format` sólo el `HEADER`, `buttons` sólo el `BUTTONS`. Se leen todos en el mismo objeto porque
 * la lista es heterogénea y quien la recorre busca por tipo ({@see PlantillaMeta::componente()}).
 */
final readonly class ComponenteDePlantillaMeta
{
    /**
     * @param list<BotonDePlantillaMeta> $botones
     */
    public function __construct(
        /** Tal cual llega; se compara en mayúsculas en {@see self::esDeTipo()}. */
        public ?string $tipo,
        /** Sólo `HEADER`: `TEXT`, `IMAGE`, `VIDEO`, `DOCUMENT`. */
        public ?string $formato,
        /** El texto con sus `{{marcadores}}`. Vacío en un `BUTTONS` y en un `HEADER` de imagen. */
        public ?string $texto,
        public array $botones,
    ) {}

    /** @param array<mixed> $componente */
    public static function fromArray(array $componente): self
    {
        return new self(
            tipo: Lee::texto($componente['type'] ?? null),
            formato: Lee::texto($componente['format'] ?? null),
            texto: Lee::texto($componente['text'] ?? null),
            botones: array_map(
                BotonDePlantillaMeta::fromArray(...),
                Lee::listaDeMapas($componente['buttons'] ?? null),
            ),
        );
    }

    /** `BODY`, `HEADER`… Sin distinguir mayúsculas, como hacía el sincronizador con `strtoupper()`. */
    public function esDeTipo(string $tipo): bool
    {
        return strtoupper($this->tipo ?? '') === strtoupper($tipo);
    }
}
