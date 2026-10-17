<?php

declare(strict_types=1);

namespace App\Tests\Agent\Skill\Pms;

use App\Agent\Access\ActorInterface;
use App\Agent\Skill\Pms\ReservaDeLaConsulta;
use PHPUnit\Framework\Attributes\CoversClass;
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
}
