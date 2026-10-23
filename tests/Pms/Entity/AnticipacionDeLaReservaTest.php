<?php

declare(strict_types=1);

namespace App\Tests\Pms\Entity;

use App\Agent\Skill\Pms\ConsultarOcupacionSkill;
use App\Pms\Dto\PmsOcupacionDto;
use App\Pms\Entity\PmsReserva;
use DateTime;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Cuándo se reservó y con cuánta antelación: la regla en la reserva y la media que da el agente.
 */
#[CoversClass(PmsReserva::class)]
#[CoversClass(ConsultarOcupacionSkill::class)]
final class AnticipacionDeLaReservaTest extends TestCase
{
    public function testMandaLaFechaDelCanalSobreLaDeAlta(): void
    {
        $r = $this->reserva('2026-11-10', canal: '2026-10-01 23:40:00', alta: '2026-10-20 09:00:00');

        self::assertSame('2026-10-01', $r->getFechaReserva()?->format('Y-m-d'));
        self::assertSame(40, $r->getAnticipacionDias());
    }

    public function testSinFechaDelCanalCuentaLaDeAlta(): void
    {
        self::assertSame(5, $this->reserva('2026-11-10', canal: null, alta: '2026-11-05 22:00:00')->getAnticipacionDias());
    }

    public function testReservarElMismoDiaEsCero(): void
    {
        self::assertSame(0, $this->reserva('2026-11-10', canal: '2026-11-10 08:00:00', alta: null)->getAnticipacionDias());
    }

    public function testLaMediaCuentaReservasQueEntranEnElRangoUnaVezCadaUna(): void
    {
        $ocupacion = [
            $this->estancia('r1', '2026-11-03', 10),
            $this->estancia('r1', '2026-11-03', 10),          // la misma reserva en otra casita
            $this->estancia('r2', '2026-11-20', 30),
            $this->estancia('r3', '2026-10-28', 90),          // entró el mes anterior: no cuenta
            $this->estancia(null, '2026-11-05', null, false), // un bloqueo
        ];

        $a = ConsultarOcupacionSkill::anticipacion($ocupacion, '2026-11-01', '2026-12-01');

        self::assertSame(2, $a['reservas'] ?? null);
        self::assertSame(20.0, $a['media_dias'] ?? null);
        self::assertSame(20.0, $a['mediana_dias'] ?? null);
    }

    public function testSinReservasQueEntrenNoHayMedia(): void
    {
        self::assertNull(ConsultarOcupacionSkill::anticipacion([$this->estancia('r3', '2026-10-28', 90)], '2026-11-01', '2026-12-01'));
    }

    private function reserva(string $llegada, ?string $canal, ?string $alta): PmsReserva
    {
        $r = (new PmsReserva())->setFechaLlegada(new DateTime($llegada));
        $r->setPrimeraFechaReservaCanal($canal !== null ? new DateTime($canal) : null);
        $r->setCreatedAt($alta !== null ? new DateTimeImmutable($alta) : null);

        return $r;
    }

    private function estancia(?string $reserva, string $entra, ?int $dias, bool $esEstancia = true): PmsOcupacionDto
    {
        return new PmsOcupacionDto(
            casita: 'Casita 1', casitaId: 'u1', establecimiento: 'E', huesped: 'X', entra: $entra, sale: '2026-12-31',
            horaEntrada: null, horaSalida: null, estado: 'confirmada', esEstancia: $esEstancia, esOta: false,
            localizador: null, reservaId: $reserva, eventoId: 'e', anticipacionDias: $dias,
        );
    }
}
