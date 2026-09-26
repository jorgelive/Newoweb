<?php

declare(strict_types=1);

namespace App\Tests\Pms\Entity;

use App\Pms\Entity\PmsEstablecimiento;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsTarifaRango;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Entity\PmsUnidadBeds24Map;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Qué puede faltar en el PMS y qué no: lo decide el dominio, no que hoy no haya nulos.
 *
 * Ver `docs/PmsBeds24ReservasSync.md` — «Nulos: decide el dominio, no los datos».
 */
#[CoversClass(PmsEventoCalendario::class)]
final class NulosDelDominioTest extends TestCase
{
    /**
     * 🔥 **Un evento sin reserva es un caso de negocio: los bloqueos.** En producción, 2 de 4
     * bloqueos no tienen reserva y los dos están enlazados a Beds24. Si alguien añade un
     * `getReservaOrFail()` para callar al analizador y lo usa en la sincronización, los bloqueos
     * dejan de sincronizarse. Este test existe para que eso sea una decisión y no un descuido.
     */
    public function testLaReservaDelEventoNoTieneOrFailPorqueLosBloqueosNoLaTienen(): void
    {
        self::assertFalse(method_exists(PmsEventoCalendario::class, 'getReservaOrFail'));
        self::assertNull((new PmsEventoCalendario())->getReserva());
    }

    /**
     * Lo que es `NOT NULL` en la base falla CON NOMBRE si falta: un dato roto que se dice, en vez
     * de un «Call to a member function on null» en la línea siguiente.
     *
     * @param callable(): mixed $pedir
     */
    #[DataProvider('obligatorios')]
    public function testLoObligatorioFallaDiciendoQueFalta(callable $pedir, string $queFalta): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($queFalta, '/') . '/');

        $pedir();
    }

    /** @return iterable<string, array{callable(): mixed, string}> */
    public static function obligatorios(): iterable
    {
        yield 'unidad del evento' => [static fn () => (new PmsEventoCalendario())->getPmsUnidadOrFail(), 'Evento sin unidad'];
        yield 'inicio del evento' => [static fn () => (new PmsEventoCalendario())->getInicioOrFail(), 'Evento sin fecha de inicio'];
        yield 'fin del evento' => [static fn () => (new PmsEventoCalendario())->getFinOrFail(), 'Evento sin fecha de fin'];
        yield 'establecimiento de la unidad' => [static fn () => (new PmsUnidad())->getEstablecimientoOrFail(), 'Unidad sin establecimiento'];
        yield 'config de Beds24' => [static fn () => (new PmsEstablecimiento())->getBeds24ConfigOrFail(), 'sin configuración de Beds24'];
        yield 'unidad del mapeo' => [static fn () => (new PmsUnidadBeds24Map())->getPmsUnidadOrFail(), 'Mapeo de Beds24 sin unidad'];
        yield 'unidad del rango' => [static fn () => (new PmsTarifaRango())->getUnidadOrFail(), 'Rango de tarifa sin unidad'];
    }

    /** Y cuando el dato está, se devuelve tal cual. */
    public function testConElDatoPuestoDevuelveLoMismoQueElGetter(): void
    {
        $unidad = new PmsUnidad();
        $evento = (new PmsEventoCalendario())->setPmsUnidad($unidad);

        self::assertSame($unidad, $evento->getPmsUnidadOrFail());
    }
}
