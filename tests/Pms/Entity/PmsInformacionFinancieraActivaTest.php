<?php

declare(strict_types=1);

namespace App\Tests\Pms\Entity;

use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Entity\PmsReserva;
use PHPUnit\Framework\TestCase;

/**
 * `activa` se calcula de las estancias: anulada sólo si TODAS están canceladas.
 *
 * Era una columna que se apagaba con la última cancelación y nada volvía a encender. El caso que
 * lo destapó es B5X9HB (08/10/2026): Booking cancelada y el arreglo nuevo como estancia directa
 * confirmada en la misma reserva, con el panel todavía en «ANULADA».
 */
final class PmsInformacionFinancieraActivaTest extends TestCase
{
    public function testConTodasLasEstanciasCanceladasEstaAnulada(): void
    {
        self::assertFalse($this->ficha(PmsEventoEstado::CODIGO_CANCELADA, PmsEventoEstado::CODIGO_CANCELADA)->isActiva());
    }

    /** B5X9HB: la de Booking cancelada y la directa nueva confirmada. */
    public function testUnaEstanciaNuevaEnPieLaReactivaSinTocarNada(): void
    {
        self::assertTrue($this->ficha(PmsEventoEstado::CODIGO_CANCELADA, PmsEventoEstado::CODIGO_CONFIRMADA)->isActiva());
    }

    public function testUnaReservaSinEstanciasNoEsUnaCancelacion(): void
    {
        self::assertTrue($this->ficha()->isActiva());
        self::assertTrue((new PmsInformacionFinanciera())->isActiva());
    }

    private function ficha(string ...$estados): PmsInformacionFinanciera
    {
        $reserva = new PmsReserva();

        foreach ($estados as $codigo) {
            $estado = new PmsEventoEstado();
            $estado->setId($codigo);

            $reserva->addEventosCalendario((new PmsEventoCalendario())->setEstado($estado));
        }

        return (new PmsInformacionFinanciera())->setReserva($reserva);
    }
}
