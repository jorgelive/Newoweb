<?php

declare(strict_types=1);

namespace App\Tests\Pms\Service\Reserva;

use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsReserva;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Service\Reserva\CadenaDeEstancia;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Una noche extra es una segunda estancia en la misma casita y cuenta como UNA estancia.
 * El caso real: 8YXYNK, Casita 1, 10→14 por Booking y 14→15 directa.
 */
#[CoversClass(CadenaDeEstancia::class)]
final class CadenaDeEstanciaTest extends TestCase
{
    private PmsReserva $reserva;
    private PmsUnidad $casita1;

    protected function setUp(): void
    {
        $this->reserva = new PmsReserva();
        $this->casita1 = new PmsUnidad();
    }

    /** El bug: la hora de salida se apuntaba en la primera, «sale el 14/10», y salía el 15. */
    public function testLaSalidaVaEnLaUltimaYLaLlegadaEnLaPrimera(): void
    {
        $booking = $this->estancia('2026-10-10 15:00', '2026-10-14 10:00');
        $extra = $this->estancia('2026-10-14 15:00', '2026-10-15 10:00');

        self::assertSame($extra, CadenaDeEstancia::ultima($booking));
        self::assertSame($extra, CadenaDeEstancia::ultima($extra));
        self::assertSame($booking, CadenaDeEstancia::primera($extra));
        self::assertSame($booking, CadenaDeEstancia::primera($booking));
    }

    /** Tres tramos seguidos: se sigue la cadena entera, no sólo el vecino. */
    public function testUnaCadenaDeTresTramos(): void
    {
        $a = $this->estancia('2026-10-10 15:00', '2026-10-14 10:00');
        $b = $this->estancia('2026-10-14 14:00', '2026-10-15 10:00');
        $c = $this->estancia('2026-10-15 14:00', '2026-10-17 10:00');

        self::assertSame($c, CadenaDeEstancia::ultima($a));
        self::assertSame($a, CadenaDeEstancia::primera($c));
    }

    /** Cambiar de casita a mitad SÍ es salir y llegar: la cadena se corta ahí. */
    public function testOtraCasitaCortaLaCadena(): void
    {
        $a = $this->estancia('2026-10-10 15:00', '2026-10-14 10:00');
        $b = $this->estancia('2026-10-14 15:00', '2026-10-15 10:00', new PmsUnidad());

        self::assertSame($a, CadenaDeEstancia::ultima($a));
        self::assertFalse(CadenaDeEstancia::continua($a, $b));
    }

    /** Una noche extra cancelada no alarga nada; un hueco de días, tampoco. */
    public function testCanceladaOConHuecoNoEncadena(): void
    {
        $a = $this->estancia('2026-10-10 15:00', '2026-10-14 10:00');
        $cancelada = $this->estancia('2026-10-14 15:00', '2026-10-15 10:00', estado: 'cancelada');
        $otraSemana = $this->estancia('2026-10-20 15:00', '2026-10-22 10:00');

        self::assertSame($a, CadenaDeEstancia::ultima($a));
        self::assertFalse(CadenaDeEstancia::continua($a, $cancelada));
        self::assertFalse(CadenaDeEstancia::continua($a, $otraSemana));
    }

    /** Otra reserva en la misma casita el mismo día es un cambio de huésped de verdad. */
    public function testOtraReservaNoEncadena(): void
    {
        $a = $this->estancia('2026-10-10 15:00', '2026-10-14 10:00');

        $otro = (new PmsEventoCalendario())
            ->setPmsUnidad($this->casita1)
            ->setInicio(new DateTimeImmutable('2026-10-14 14:00'))
            ->setFin(new DateTimeImmutable('2026-10-16 10:00'));
        $otro->setEstado($this->estado(PmsEventoEstado::CODIGO_CONFIRMADA));
        (new PmsReserva())->addEventosCalendario($otro);

        self::assertFalse(CadenaDeEstancia::continua($a, $otro));
    }

    /** Lo que usa el calendario: sobre un lote, quién sigue y quién viene. */
    public function testLasMarcasDelLote(): void
    {
        $a = $this->estancia('2026-10-10 15:00', '2026-10-14 10:00');
        $b = $this->estancia('2026-10-14 15:00', '2026-10-15 10:00');

        $marcas = CadenaDeEstancia::marcas([$a, $b]);

        self::assertSame(['sigue' => true, 'viene' => false], $marcas[spl_object_id($a)]);
        self::assertSame(['sigue' => false, 'viene' => true], $marcas[spl_object_id($b)]);
    }

    private function estancia(string $inicio, string $fin, ?PmsUnidad $unidad = null, string $estado = PmsEventoEstado::CODIGO_CONFIRMADA): PmsEventoCalendario
    {
        $evento = (new PmsEventoCalendario())
            ->setPmsUnidad($unidad ?? $this->casita1)
            ->setInicio(new DateTimeImmutable($inicio))
            ->setFin(new DateTimeImmutable($fin));
        $evento->setEstado($this->estado($estado));
        $this->reserva->addEventosCalendario($evento);

        return $evento;
    }

    private function estado(string $codigo): PmsEventoEstado
    {
        $estado = new PmsEventoEstado();
        $estado->setId($codigo);

        return $estado;
    }
}
