<?php

declare(strict_types=1);

namespace App\Tests\Agent\Skill;

use App\Agent\Access\AgentActor;
use App\Agent\Access\RestriccionCanal;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Skill\SkillDeImportesInterface;
use App\Agent\Skill\SkillRegistry;
use App\Agent\Access\ActorInterface;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillResult;
use App\Contract\VinculoComercial;
use App\Security\Roles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Las herramientas de dinero no existen para quien consulta desde una OTA sin confirmar.
 *
 * Allí el precio lo pone la plataforma. Pedírselo al modelo no basta: si la herramienta está en
 * su lista, la usa y la cifra acaba escrita. Ver `RestriccionCanal::ocultaImportes()`.
 */
final class SkillDeImportesTest extends TestCase
{
    #[Test]
    public function la_consulta_de_ota_no_ve_las_herramientas_de_dinero(): void
    {
        self::assertSame(['consultar_guia'], $this->catalogo(RestriccionCanal::OtaPreReserva));
    }

    #[Test]
    public function el_huesped_confirmado_si_las_ve(): void
    {
        self::assertSame(['consultar_guia', 'consultar_cuenta'], $this->catalogo(RestriccionCanal::Ninguna));
    }

    #[Test]
    public function solo_la_restriccion_de_ota_oculta_importes(): void
    {
        self::assertTrue(RestriccionCanal::OtaPreReserva->ocultaImportes());
        self::assertFalse(RestriccionCanal::Ninguna->ocultaImportes());
    }

    /** @return list<string> */
    private function catalogo(RestriccionCanal $restriccion): array
    {
        $actor = AgentActor::huesped('beds24', 'pms_reserva', 'r', null, VinculoComercial::Interesado, $restriccion);

        $guia = new HerramientaDoble('consultar_guia', [Roles::HUESPED], NivelRiesgo::Lectura);
        $cuenta = new class ('consultar_cuenta', [Roles::HUESPED], NivelRiesgo::Lectura) extends HerramientaDoble implements SkillDeImportesInterface {};

        return array_values(array_map(
            static fn (SkillInterface $s): string => $s->nombre(),
            (new SkillRegistry([$guia, $cuenta]))->paraActor($actor)
        ));
    }
}

/** Una herramienta de mentira: sólo importa su nombre, sus roles y si es de dinero. */
class HerramientaDoble implements SkillInterface
{
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
}
