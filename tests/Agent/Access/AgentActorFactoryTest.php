<?php

declare(strict_types=1);

namespace App\Tests\Agent\Access;

use App\Agent\Access\AgentActorFactory;
use App\Entity\User;
use App\Message\Service\EnumeradorDeFrentes;
use App\Security\Roles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Role\RoleHierarchy;

/**
 * Con qué roles llega al agente quien escribe desde su móvil.
 *
 * Dos cosas que sólo se ven aquí y cuyo fallo es silencioso: que la jerarquía de
 * `security.yaml` se aplique (sin ella, quien tiene DELETE no puede lo que sí puede en el
 * panel) y que a un usuario deshabilitado se le retire la escritura (sin eso, deshabilitar a
 * alguien en el panel no le quita nada por WhatsApp).
 *
 * Unitario puro: una `RoleHierarchy` de verdad con el mapa real, sin contenedor ni base.
 */
final class AgentActorFactoryTest extends TestCase
{
    private function factoria(): AgentActorFactory
    {
        // El mismo mapa que security.yaml, en lo que toca a estos roles.
        $jerarquia = new RoleHierarchy([
            Roles::RESERVAS_DELETE => [Roles::RESERVAS_WRITE],
            Roles::RESERVAS_WRITE  => [Roles::RESERVAS_SHOW],
            Roles::MENSAJES_DELETE => [Roles::MENSAJES_WRITE],
            Roles::MENSAJES_WRITE  => [Roles::MENSAJES_SHOW],
        ]);

        return new AgentActorFactory($jerarquia, new EnumeradorDeFrentes([]));
    }

    /** @param list<string> $roles */
    private function usuario(array $roles, bool $activo): User
    {
        $u = new User();
        $u->setEmail('doble@ejemplo');
        $u->setRoles($roles);
        $u->setEnabled($activo);

        return $u;
    }

    /**
     * La jerarquía se aplica: quien tiene DELETE puede lo que pida WRITE.
     *
     * Sin esto el agente se comporta distinto que el panel con la misma cuenta, y el síntoma
     * es «el bot ya no sabe hacer eso» sin un solo error en ningún log.
     */
    #[Test]
    public function el_delete_alcanza_al_write_por_jerarquia(): void
    {
        $actor = $this->factoria()->delEquipoPorChat(
            $this->usuario([Roles::RESERVAS_DELETE], activo: true),
            'whatsapp_meta'
        );

        self::assertTrue($actor->tieneRol(Roles::RESERVAS_WRITE), 'la jerarquía no se expandió');
        self::assertTrue($actor->tieneRol(Roles::RESERVAS_SHOW));
    }

    /**
     * 🔒 EL QUE IMPORTA. Deshabilitar en el panel es el gesto de revocar, y desde que el
     * equipo escribe por WhatsApp tiene que revocar también ahí.
     */
    #[Test]
    public function un_usuario_deshabilitado_pierde_la_escritura(): void
    {
        $actor = $this->factoria()->delEquipoPorChat(
            $this->usuario([Roles::RESERVAS_DELETE, Roles::MENSAJES_DELETE], activo: false),
            'whatsapp_meta'
        );

        self::assertFalse($actor->tieneRol(Roles::RESERVAS_DELETE), 'un ex-empleado no registra pagos');
        self::assertFalse($actor->tieneRol(Roles::RESERVAS_WRITE), 'ni por la puerta de atrás de la jerarquía');
        self::assertFalse($actor->tieneRol(Roles::MENSAJES_WRITE), 'ni le escribe a huéspedes reales');
    }

    /**
     * …pero sigue identificado y sigue consultando. `enabled = false` significa DOS cosas en
     * este sistema, y la otra es «nunca tuvo login»: la limpiadora que cobra no puede quedarse
     * como una desconocida.
     */
    #[Test]
    public function un_deshabilitado_conserva_la_lectura_y_la_identidad(): void
    {
        $actor = $this->factoria()->delEquipoPorChat(
            $this->usuario([Roles::RESERVAS_DELETE, Roles::LIMPIEZA], activo: false),
            'whatsapp_meta'
        );

        self::assertTrue($actor->esDelEquipo(), 'dejaría de ser identificable');
        self::assertTrue($actor->tieneRol(Roles::RESERVAS_SHOW), 'la consulta no se toca');
        self::assertTrue($actor->tieneRol(Roles::LIMPIEZA), 'los roles operativos tampoco');
    }

    /** Un usuario activo no pierde nada: la poda es sólo para deshabilitados. */
    #[Test]
    public function un_usuario_activo_conserva_todo(): void
    {
        $actor = $this->factoria()->delEquipoPorChat(
            $this->usuario([Roles::RESERVAS_DELETE], activo: true),
            'whatsapp_meta'
        );

        self::assertTrue($actor->tieneRol(Roles::RESERVAS_DELETE));
        self::assertTrue($actor->tieneRol(Roles::RESERVAS_WRITE));
    }
}
