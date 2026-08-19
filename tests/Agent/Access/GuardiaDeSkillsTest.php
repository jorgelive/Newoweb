<?php

declare(strict_types=1);

namespace App\Tests\Agent\Access;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\GuardiaDeSkills;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Access\RestriccionCanal;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillResult;
use App\Entity\User;
use App\Contract\VinculoComercial;
use App\Security\Roles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * El cierre antes de ejecutar una skill.
 *
 * Se prueba porque es el único punto por el que pasa TODA ejecución, y porque su fallo es
 * silencioso en las dos direcciones: si deja pasar de más, se ejecuta algo que nadie autorizó;
 * si bloquea de más, el bot «ya no sabe» hacer algo y nadie sabe por qué.
 *
 * Unitario puro: skills anónimas y un actor de doble, sin contenedor ni base de datos.
 */
final class GuardiaDeSkillsTest extends TestCase
{
    /** @param list<string> $roles */
    private function skill(
        array $roles = [],
        NivelRiesgo $riesgo = NivelRiesgo::Lectura,
        string $nombre = 'skill_doble',
    ): SkillInterface {
        return new class ($nombre, $roles, $riesgo) implements SkillInterface {
            /** @param list<string> $roles */
            public function __construct(
                private readonly string $nombre,
                private readonly array $roles,
                private readonly NivelRiesgo $riesgo,
            ) {}

            public function nombre(): string { return $this->nombre; }
            public function definicion(): SkillDefinition { return new SkillDefinition(descripcion: 'doble'); }
            public function ejecutar(array $entrada, ActorInterface $actor): SkillResult { return SkillResult::ok([]); }
            public function rolesRequeridos(): array { return $this->roles; }
            public function nivelRiesgo(): NivelRiesgo { return $this->riesgo; }
        };
    }

    /** @param list<string> $roles */
    private function actor(array $roles = []): ActorInterface
    {
        return new class ($roles) implements ActorInterface {
            /** @param list<string> $roles */
            public function __construct(private readonly array $roles) {}

            public function roles(): array { return $this->roles; }
            public function origen(): string { return 'test'; }
            public function contextoTipo(): ?string { return null; }
            public function contextoId(): ?string { return null; }
            public function conversacionId(): ?string { return null; }
            public function vinculo(): VinculoComercial { return VinculoComercial::Ninguno; }
            public function restriccion(): RestriccionCanal { return RestriccionCanal::Ninguna; }
            public function dominios(): array { return []; }
            public function esDelEquipo(): bool { return true; }
            public function esProspecto(): bool { return false; }
            public function usuario(): ?User { return null; }
            public function tieneRol(string $rol): bool { return in_array($rol, $this->roles, true); }
            public function tieneAlguno(array $roles): bool
            {
                if ($roles === []) {
                    return true;
                }

                foreach ($roles as $rol) {
                    if ($this->tieneRol($rol)) {
                        return true;
                    }
                }

                return false;
            }
            public function etiqueta(): string { return 'doble'; }
        };
    }

    #[Test]
    public function deja_pasar_una_lectura_sin_roles(): void
    {
        $guardia = new GuardiaDeSkills();

        self::assertNull($guardia->motivoDeBloqueo($this->skill(), $this->actor()));
    }

    #[Test]
    public function deja_pasar_una_escritura_a_quien_tiene_el_rol(): void
    {
        $guardia = new GuardiaDeSkills();

        self::assertNull($guardia->motivoDeBloqueo(
            $this->skill([Roles::RESERVAS_WRITE], NivelRiesgo::Escritura),
            $this->actor([Roles::RESERVAS_WRITE])
        ));
    }

    #[Test]
    public function bloquea_a_quien_no_tiene_el_rol_y_dice_cual_falta(): void
    {
        $guardia = new GuardiaDeSkills();

        $motivo = $guardia->motivoDeBloqueo(
            $this->skill([Roles::RESERVAS_WRITE], NivelRiesgo::Escritura),
            $this->actor([Roles::HUESPED])
        );

        self::assertNotNull($motivo);
        // El motivo se lo come un modelo: tiene que poder decirle al usuario qué falta.
        self::assertStringContainsString(Roles::RESERVAS_WRITE, $motivo);
    }
}
