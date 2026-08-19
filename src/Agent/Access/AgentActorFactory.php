<?php

declare(strict_types=1);

namespace App\Agent\Access;

use App\Entity\User;
use App\Contract\VinculoComercial;
use App\Message\Service\EnumeradorDeFrentes;
use App\Security\Roles;
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
    /**
     * Los que se le retiran a un usuario deshabilitado. Ver `delEquipoPorChat()`.
     *
     * Enumerados y no deducidos del sufijo `_WRITE`/`_DELETE`: un rol nuevo que conceda
     * escritura sin llamarse así se colaría en silencio, y el fallo sería invisible. Si añades
     * un rol que permita escribir, añádelo aquí — `SUPER_ADMIN` está por lo mismo, porque la
     * jerarquía lo expande a todo.
     *
     * @var list<string>
     */
    private const array ROLES_DE_ESCRITURA = [
        Roles::SUPER_ADMIN,
        Roles::ADMIN,
        Roles::OPERACIONES_WRITE,
        Roles::OPERACIONES_DELETE,
        Roles::RESERVAS_WRITE,
        Roles::RESERVAS_DELETE,
        Roles::MENSAJES_WRITE,
        Roles::MENSAJES_DELETE,
        Roles::MAESTROS_WRITE,
        Roles::MAESTROS_DELETE,
    ];

    public function __construct(
        private RoleHierarchyInterface $jerarquia,
        private EnumeradorDeFrentes $frentes,
    ) {}

    /** Un miembro del equipo con sesión en el panel. */
    public function delPanel(User $usuario): AgentActor
    {
        return AgentActor::delPanel($usuario, $this->rolesEfectivos($usuario));
    }

    /**
     * Un miembro del equipo escribiendo desde su móvil.
     *
     * @param bool $tambienHuesped Suma `ROLE_HUESPED` a sus roles de equipo.
     *
     *        Los privilegios son ACUMULATIVOS, como en cualquier ACL: alguien del equipo
     *        con una reserva a su nombre es las dos cosas a la vez, y debe poder consultar
     *        su propia estancia sin dejar de ser operador. Sin esto, registrar el móvil de
     *        una persona del equipo le quitaba en silencio las skills de huésped en su
     *        propia conversación de reserva.
     *
     *        Las skills de huésped siguen acotadas por el CONTEXTO de la conversación
     *        —`ConsultarMiReservaSkill` ni siquiera acepta un parámetro con el que apuntar
     *        a otra—, así que sumar el rol no abre nada de nadie más.
     *
     *        Va apagado por defecto: en el panel y en la CLI el actor no es huésped de
     *        nada, y encenderlo allí sería ruido.
     */
    public function delEquipoPorChat(
        User $usuario,
        string $origen,
        ?string $tipo = null,
        ?string $id = null,
        bool $tambienHuesped = false,
        ?string $conversacionId = null
    ): AgentActor {
        $roles = $this->rolesEfectivos($usuario);

        // 🔒 DESHABILITADO ⇒ SE QUEDA SIN ESCRITURA. Es la revocación.
        //
        // `findByTelefono()` devuelve al usuario aunque esté deshabilitado, y con razón: en
        // este sistema `enabled = false` significa DOS cosas distintas —«se le retiró el
        // acceso» y «nunca tuvo login», como la limpiadora que cobra (§11.5.1 de
        // PmsBeds24ReservasSync)—. Filtrarlo allí dejaría a la segunda como desconocida.
        //
        // Su docblock decía que «identificar no es autorizar, quien decide es el actor con sus
        // roles», y era verdad mientras el canal fuese de sólo lectura. Desde que el equipo
        // escribe por WhatsApp dejó de serlo: nadie entre identificar y ejecutar miraba
        // `enabled`, así que deshabilitar a alguien en el panel —el gesto natural de
        // revocación— NO le quitaba nada por WhatsApp. Un ex-empleado conservaba
        // `registrar_pago`, `cambiar_codigo_caja` y `enviar_mensaje_huesped` indefinidamente.
        //
        // Se resuelve aquí y no en el repositorio para no romper la identificación: sigue
        // siendo del equipo, sigue saliendo su nombre, sigue pudiendo consultar. Sólo pierde
        // lo que escribe. La limpiadora sin login no pierde nada, porque nunca tuvo estos
        // roles. Ojo al contraste: el canal de VOZ ya lo comprobaba
        // ({@see \App\Agent\Alexa\AlexaUsuarios}) siendo de sólo lectura.
        if (!$usuario->isEnabled()) {
            $roles = array_values(array_diff($roles, self::ROLES_DE_ESCRITURA));
        }

        if ($tambienHuesped) {
            $roles[] = Roles::HUESPED;
            $roles = array_values(array_unique($roles));
        }

        return AgentActor::delEquipoPorChat($usuario, $origen, $tipo, $id, $roles, $conversacionId);
    }

    /**
     * Los roles del huésped no se expanden: `ROLE_HUESPED` es sintético y plano —ningún
     * `User` lo tiene—, así que no hay jerarquía que aplicarle. Se ofrece aquí para que quien
     * construye actores no tenga que alternar entre la factoría y la clase.
     */
    public function huesped(
        string $origen,
        ?string $contextoTipo,
        ?string $contextoId,
        ?string $conversacionId = null,
        VinculoComercial $vinculo = VinculoComercial::Cliente,
        RestriccionCanal $restriccion = RestriccionCanal::Ninguna
    ): AgentActor {
        return AgentActor::huesped(
            $origen,
            $contextoTipo,
            $contextoId,
            $conversacionId,
            $vinculo,
            $restriccion,
            $this->frentes->dominiosPara($contextoTipo)
        );
    }

    /**
     * Quien pregunta sin reserva ninguna. `ROLE_PROSPECTO` es sintético y plano, igual que
     * `ROLE_HUESPED`: tampoco hay jerarquía que expandirle.
     */
    public function prospecto(string $origen, ?string $conversacionId = null): AgentActor
    {
        // Sin contexto, pero NO sin dominios: la venta está abierta para cualquiera, y un
        // prospecto es exactamente para quien está abierta. Dejarlo sin negocios lo habría
        // dejado sin catálogo justo a quien más interesa atender.
        return AgentActor::prospecto($origen, $conversacionId, $this->frentes->dominiosPara(null));
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
