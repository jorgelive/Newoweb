<?php

declare(strict_types=1);

namespace App\Agent\Skill;

use App\Agent\Access\ActorInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * El catálogo de skills, y quién puede usar cada una.
 *
 * 🔑 Lo que un actor no puede usar **ni se le menciona al modelo**. No es que lo pida y se le
 * deniegue: es que no existe en su contexto. Un modelo no puede invocar algo que no sabe que
 * hay — y de paso se ahorran los tokens de su definición.
 *
 * Es dominio puro: no sabe de proveedores. El adaptador de cada motor consulta este registro
 * y traduce lo que reciba.
 */
final readonly class SkillRegistry
{
    /**
     * @param iterable<SkillInterface> $skills
     */
    public function __construct(
        #[AutowireIterator('app.agent_skill')]
        private iterable $skills
    ) {}

    /**
     * @return list<SkillInterface>
     */
    public function paraActor(ActorInterface $actor, bool $incluirEscritura = true): array
    {
        $permitidas = [];

        foreach ($this->skills as $skill) {
            // Apagada por configuración: no existe para nadie, ni siquiera para un
            // SUPER_ADMIN. Va ANTES que los roles porque no es una cuestión de permisos —
            // la skill no puede funcionar, se tengan los que se tengan.
            if ($skill instanceof SkillConmutableInterface && !$skill->estaActiva()) {
                continue;
            }

            if (!$actor->tieneAlguno($skill->rolesRequeridos())) {
                continue;
            }

            // Ojo: NO es «deja fuera todo lo que escriba». `NivelRiesgo::Interna` escribe
            // —avisa al equipo, marca la conversación— y sobrevive a este filtro a propósito:
            // sin ella el chat del huésped se quedaba sin la única herramienta que le sirve
            // para pedir ayuda. Ver NivelRiesgo::exigePermisoDeEscritura().
            if (!$incluirEscritura && $skill->nivelRiesgo()->exigePermisoDeEscritura()) {
                continue;
            }

            $permitidas[] = $skill;
        }

        return $permitidas;
    }

    public function buscar(string $nombre): ?SkillInterface
    {
        foreach ($this->skills as $skill) {
            if ($skill->nombre() === $nombre) {
                return $skill;
            }
        }

        return null;
    }

    /** @return list<SkillInterface> */
    public function todas(): array
    {
        return iterator_to_array($this->skills, false);
    }
}
