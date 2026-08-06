<?php

declare(strict_types=1);

namespace App\Agent\Skill;

/**
 * Cómo se le presenta una skill al modelo, en términos neutrales.
 *
 * 🔥 La descripción ES prompt, no documentación: di **cuándo** usarla, no sólo qué hace, y
 * prohíbe explícitamente responder de memoria si el dato tiene que salir de aquí. Eso es lo
 * que sube la tasa de invocación.
 */
final readonly class SkillDefinition
{
    /**
     * @param list<SkillParameter> $parametros
     */
    public function __construct(
        public string $descripcion,
        public array $parametros = [],
    ) {}

    /** @return list<SkillParameter> */
    public function requeridos(): array
    {
        return array_values(array_filter($this->parametros, static fn (SkillParameter $p) => $p->requerido));
    }
}
