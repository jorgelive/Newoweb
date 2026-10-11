<?php

declare(strict_types=1);

namespace App\Tests\Pms\Service\Finance;

use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Entity\PmsReserva;
use App\Pms\Enum\PmsQueSePide;
use App\Pms\Service\Finance\PmsPrepagoCalculador;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Cuándo se pide el TOTAL antes del día de llegada (10/10/2026): total pedido por el operador, o
 * reserva de última hora. Ver `PmsPrepagoCalculador::pideElTotal()`.
 */
final class PmsPrepagoCalculadorTotalTest extends TestCase
{
    private PmsPrepagoCalculador $calculador;

    protected function setUp(): void
    {
        $this->calculador = new PmsPrepagoCalculador();
    }

    /** DW864U: reservada la víspera. Recibió adelanto y, seis horas después, saldo. */
    public function testReservadaLaVisperaEsDeUltimaHora(): void
    {
        self::assertTrue($this->calculador->esDeUltimaHora($this->ficha(reservadaHaceDias: 1, llegaEnDias: 0)));
    }

    public function testConDosDiasDeAntelacionTodaviaEsDeUltimaHora(): void
    {
        self::assertTrue($this->calculador->esDeUltimaHora($this->ficha(reservadaHaceDias: 0, llegaEnDias: 2)));
    }

    public function testConTresDiasYaNo(): void
    {
        self::assertFalse($this->calculador->esDeUltimaHora($this->ficha(reservadaHaceDias: 0, llegaEnDias: 3)));
    }

    /**
     * La antelación se mide AL RESERVAR. Reservada hace meses y llegando pasado mañana sigue
     * siendo de adelanto: su enlace no puede cambiar dos días antes de llegar.
     */
    public function testUnaReservaAntiguaNoSeVuelveDeUltimaHoraAlAcercarseLaLlegada(): void
    {
        self::assertFalse($this->calculador->esDeUltimaHora($this->ficha(reservadaHaceDias: 90, llegaEnDias: 2)));
    }

    public function testElTotalPedidoSePideAunqueFalteUnMes(): void
    {
        $ficha = $this->ficha(reservadaHaceDias: 10, llegaEnDias: 30)->setCobroTotalPedido(true);

        self::assertSame(PmsQueSePide::TOTAL, $this->calculador->queSePide($ficha));
    }

    public function testLaDeUltimaHoraPideElTotal(): void
    {
        self::assertSame(PmsQueSePide::TOTAL, $this->calculador->queSePide($this->ficha(reservadaHaceDias: 0, llegaEnDias: 1)));
    }

    /** Sin fecha del canal, cuenta cuándo nació en el sistema. */
    public function testSinFechaDelCanalSeUsaLaDeAlta(): void
    {
        $reserva = (new PmsReserva())->setFechaLlegada(new DateTimeImmutable('+1 day'));
        $reserva->setCreatedAt(new DateTimeImmutable('today'));

        self::assertTrue($this->calculador->esDeUltimaHora((new PmsInformacionFinanciera())->setReserva($reserva)));
    }

    private function ficha(int $reservadaHaceDias, int $llegaEnDias): PmsInformacionFinanciera
    {
        $reserva = (new PmsReserva())
            ->setFechaLlegada(new DateTimeImmutable(sprintf('+%d days', $llegaEnDias)))
            ->setPrimeraFechaReservaCanal(new DateTimeImmutable(sprintf('-%d days', $reservadaHaceDias)));

        return (new PmsInformacionFinanciera())->setReserva($reserva);
    }
}
