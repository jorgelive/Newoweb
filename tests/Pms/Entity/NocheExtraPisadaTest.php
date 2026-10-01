<?php

declare(strict_types=1);

namespace App\Tests\Pms\Entity;

use App\Pms\Entity\PmsEventoBeds24Link;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsReserva;
use App\Pms\Entity\PmsUnidad;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `nocheExtraPisadaPor()`: la regla con la que el aviso al equipo y el calendario deciden que un
 * canal dejó dos estancias para la misma noche. Fase 5 de docs/PlanHorarioExtraSinEventos.md.
 */
final class NocheExtraPisadaTest extends TestCase
{
    private PmsUnidad $casita;

    protected function setUp(): void
    {
        $this->casita = new PmsUnidad();
    }

    #[Test]
    public function otra_estancia_en_la_vispera_pisa_la_entrada_temprana(): void
    {
        $anna = $this->estancia('2027-02-02', '2027-02-05', entrada: true);
        $juan = $this->estancia('2027-01-30', '2027-02-02');

        self::assertSame(PmsEventoBeds24Link::ROL_EXTRA_ENTRADA, $anna->nocheExtraPisadaPor($juan)?->rol);
        self::assertNull($juan->nocheExtraPisadaPor($anna), 'Juan no tiene noche extra que le pisen');
    }

    /** Juan se va el 02 por la mañana: su última noche es la del 01, que es la víspera de Anna. */
    #[Test]
    public function quien_se_va_el_dia_que_entra_pisa_la_vispera(): void
    {
        $anna = $this->estancia('2027-02-02', '2027-02-05', entrada: true);

        self::assertNotNull($anna->nocheExtraPisadaPor($this->estancia('2027-01-31', '2027-02-02')));
        self::assertNull($anna->nocheExtraPisadaPor($this->estancia('2027-01-29', '2027-02-01')), 'se fue el 01: la víspera está libre');
    }

    /** Las dos noches extra para la misma noche también es un choque: dos `black` y dos huéspedes. */
    #[Test]
    public function la_salida_tardia_de_uno_y_la_entrada_temprana_del_siguiente_chocan(): void
    {
        $juan = $this->estancia('2027-01-29', '2027-02-01', salida: true);
        $anna = $this->estancia('2027-02-02', '2027-02-05', entrada: true);

        self::assertNotNull($anna->nocheExtraPisadaPor($juan));
        self::assertNotNull($juan->nocheExtraPisadaPor($anna));
    }

    #[Test]
    public function no_pisan_un_bloqueo_ni_otra_casita_ni_la_misma_reserva(): void
    {
        $anna = $this->estancia('2027-02-02', '2027-02-05', entrada: true);

        self::assertNull($anna->nocheExtraPisadaPor($this->estancia('2027-01-31', '2027-02-02', estado: PmsEventoEstado::CODIGO_BLOQUEO)));
        self::assertNull($anna->nocheExtraPisadaPor($this->estancia('2027-01-31', '2027-02-02', estado: PmsEventoEstado::CODIGO_CANCELADA)));
        self::assertNull($anna->nocheExtraPisadaPor($this->estancia('2027-01-31', '2027-02-02')->setPmsUnidad(new PmsUnidad())));

        $reserva = new PmsReserva();
        $anna->setReserva($reserva);
        self::assertNull($anna->nocheExtraPisadaPor($this->estancia('2027-01-31', '2027-02-02')->setReserva($reserva)), 'su otro tramo');
    }

    private function estancia(
        string $entra,
        string $sale,
        bool $entrada = false,
        bool $salida = false,
        string $estado = PmsEventoEstado::CODIGO_CONFIRMADA,
    ): PmsEventoCalendario {
        return (new PmsEventoCalendario())
            ->setPmsUnidad($this->casita)
            ->setInicio(new DateTimeImmutable("$entra 14:00"))
            ->setFin(new DateTimeImmutable("$sale 10:00"))
            ->setEntradaTemprana($entrada)
            ->setSalidaTardia($salida)
            ->setEstado(new PmsEventoEstado($estado));
    }
}
