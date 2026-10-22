<?php

declare(strict_types=1);

namespace App\Tests\Agent\Skill\Pms;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\AgentActor;
use App\Agent\Access\AsuntoDelHilo;
use App\Agent\Skill\Pms\ReservaDeLaConsulta;
use App\Entity\User;
use App\Security\Roles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReservaDeLaConsulta::class)]
final class ReservaDeLaConsultaTest extends TestCase
{
    public function testUnHuespedSiempreConsultaLaSuyaAunqueEscribaOtroId(): void
    {
        self::assertSame('suya', ReservaDeLaConsulta::de($this->actor('pms_reserva', 'suya', false), 'ajena'));
    }

    public function testElEquipoEnSuHiloDeAvisosConsultaLaQueNombra(): void
    {
        // El contexto `staff:<uuid>` es una persona, no una reserva: no puede tapar el parámetro.
        self::assertSame('nombrada', ReservaDeLaConsulta::de($this->actor('staff', 'uuid-operador', true), 'nombrada'));
    }

    public function testElEquipoSinNombrarNingunaConsultaLaDeSuConversacion(): void
    {
        self::assertSame('del-hilo', ReservaDeLaConsulta::de($this->actor('pms_reserva', 'del-hilo', true), ''));
    }

    public function testElEquipoEnSuHiloDeAvisosSinNombrarNingunaNoTieneReserva(): void
    {
        self::assertSame('', ReservaDeLaConsulta::de($this->actor('staff', 'uuid-operador', true), ''));
    }

    public function testQuienNoEsDelEquipoNiTieneReservaNoAbreNadaConUnId(): void
    {
        self::assertSame('', ReservaDeLaConsulta::de($this->actor(null, null, false), 'ajena'));
    }

    private function actor(?string $tipo, ?string $id, bool $delEquipo): ActorInterface
    {
        $actor = $this->createStub(ActorInterface::class);
        $actor->method('contextoTipo')->willReturn($tipo);
        $actor->method('contextoId')->willReturn($id);
        $actor->method('esDelEquipo')->willReturn($delEquipo);

        return $actor;
    }

    // ── Varias reservas en un hilo (21/10/2026): el modelo elige por la clave del asunto ──
    // Ver `docs/Mensajeria.md` §11, «Varias reservas en un hilo».

    private AgentActor $huesped;

    protected function setUp(): void
    {
        $this->huesped = AgentActor::huesped('whatsapp', 'pms_reserva', 'reserva-proxima')->conAsuntosDelHilo([
            new AsuntoDelHilo('R1', 'pms_reserva', 'reserva-proxima', 'Casita 6, 22/10–25/10', true),
            new AsuntoDelHilo('R2', 'pms_reserva', 'reserva-pasada', 'Casita 6, 17/10–19/10', false),
        ]);
    }

    #[Test]
    public function sin_clave_la_de_por_defecto(): void
    {
        self::assertNull(ReservaDeLaConsulta::noPermitido($this->huesped, []));
        self::assertSame('reserva-proxima', ReservaDeLaConsulta::contexto($this->huesped, ReservaDeLaConsulta::clave([])));
    }

    #[Test]
    public function con_la_clave_de_otra_de_su_hilo_esa(): void
    {
        $entrada = ['asunto' => ' r2 '];

        self::assertNull(ReservaDeLaConsulta::noPermitido($this->huesped, $entrada));
        self::assertSame('reserva-pasada', ReservaDeLaConsulta::contexto($this->huesped, ReservaDeLaConsulta::clave($entrada)));
    }

    #[Test]
    public function una_clave_ajena_o_un_identificador_no_abren_nada(): void
    {
        foreach (['R9', 'reserva-de-otro', '3Q7ZJ7'] as $intento) {
            $error = ReservaDeLaConsulta::noPermitido($this->huesped, ['asunto' => $intento]);

            self::assertNotNull($error, $intento);
            self::assertTrue($error->esError());
            // Y aunque una skill se olvidara de comprobar, no cae en otra reserva: cae en la suya.
            self::assertSame('reserva-proxima', ReservaDeLaConsulta::contexto($this->huesped, $intento));
        }
    }

    #[Test]
    public function con_una_sola_reserva_nombrar_una_es_un_error_y_lo_demas_no_cambia(): void
    {
        $solo = AgentActor::huesped('whatsapp', 'pms_reserva', 'unica');

        self::assertNotNull(ReservaDeLaConsulta::noPermitido($solo, ['asunto' => 'R2']));
        self::assertSame('unica', ReservaDeLaConsulta::contexto($solo));
    }

    #[Test]
    public function el_equipo_sigue_eligiendo_por_reserva_id(): void
    {
        $admin = (new User())->setEmail('cli@local');
        $admin->setRoles([Roles::SUPER_ADMIN]);
        $equipo = AgentActor::delPanel($admin);

        self::assertSame('la-que-nombra', ReservaDeLaConsulta::de($equipo, 'la-que-nombra'));
        // El huésped no: un `reserva_id` escrito por el modelo se ignora y queda la suya.
        self::assertSame('reserva-proxima', ReservaDeLaConsulta::de($this->huesped, 'la-que-nombra'));
    }
}
