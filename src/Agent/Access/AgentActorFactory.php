<?php

declare(strict_types=1);

namespace App\Agent\Access;

use App\Entity\User;
use Symfony\Component\Security\Core\Authorization\Voter\RoleHierarchyVoter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Construye actores del agente con los roles **efectivos** del usuario.
 *
 * ### Por qué existe
 *
 * `User::getRoles()` devuelve lo que hay en la columna `roles` más `ROLE_USER`: la jerarquía
 * de `security.yaml` NO está ahí. En el panel eso da igual —Symfony la expande al evaluar
 * `is_granted()` ({@see RoleHierarchyVoter})—, pero el agente comprueba permisos por su
 * cuenta, comparando contra la lista del actor, así que sin expandir se comportaba distinto
 * que el resto del sistema:
 *
 * ```
 * Susan tiene ROLE_RESERVAS_DELETE
 *   panel   → puede registrar un pago (DELETE ⊃ WRITE ⊃ SHOW por jerarquía)
 *   agente  → NO podía: pedía ROLE_RESERVAS_WRITE y no lo tenía literalmente
 * ```
 *
 * El síntoma era desconcertante porque `app:agent:permisos` no lo veía: prueba con perfiles
 * sintéticos que llevan el rol exacto que se está comprobando, nunca uno heredado.
 *
 * ### Lo que NO cambia
 *
 * Poder **escribir** un registro y estar autorizado a **cobrar** son cosas distintas: un
 * operador con `ROLE_RESERVAS_DELETE` apunta el pago que recibió otra persona, pero sólo
 * figura como cobrador quien tenga `ROLE_COBRADOR` (§11.5.1 de PmsBeds24ReservasSync). Ese
 * filtro mira la columna literal a propósito y esta expansión no lo afecta.
 */
final readonly class AgentActorFactory
{
    public function __construct(
        private RoleHierarchyInterface $jerarquia
    ) {}

    /** Un miembro del equipo con sesión en el panel. */
    public function delPanel(User $usuario): AgentActor
    {
        return AgentActor::delPanel($usuario, $this->rolesEfectivos($usuario));
    }

    /** Un miembro del equipo escribiendo desde su móvil. */
    public function delEquipoPorChat(
        User $usuario,
        string $origen,
        ?string $tipo = null,
        ?string $id = null
    ): AgentActor {
        return AgentActor::delEquipoPorChat($usuario, $origen, $tipo, $id, $this->rolesEfectivos($usuario));
    }

    /**
     * Los roles del huésped no se expanden: `ROLE_HUESPED` es sintético y plano —ningún
     * `User` lo tiene—, así que no hay jerarquía que aplicarle. Se ofrece aquí para que quien
     * construye actores no tenga que alternar entre la factoría y la clase.
     */
    public function huesped(string $origen, ?string $contextoTipo, ?string $contextoId): AgentActor
    {
        return AgentActor::huesped($origen, $contextoTipo, $contextoId);
    }

    /**
     * Roles literales + los que se alcanzan por jerarquía.
     *
     * @return list<string>
     */
    private function rolesEfectivos(User $usuario): array
    {
        return array_values(array_unique(
            $this->jerarquia->getReachableRoleNames($usuario->getRoles())
        ));
    }
}
