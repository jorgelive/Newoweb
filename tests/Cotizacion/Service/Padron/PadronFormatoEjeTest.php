<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Service\Padron;

use App\Cotizacion\Enum\GrupoTipoEnum;
use App\Cotizacion\Service\Padron\PadronFormato;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * El contrato del que se cuelga el ciclo del padrón: **lo que escribe la exportación, la
 * importación lo tiene que saber leer**.
 *
 * ⚠️ Esto no existía, y por eso se rompió. `ejeDe()` leía el sufijo sólo para la reserva aérea
 * mientras `cabeceraDeEje()` lo escribía para cualquier eje: exportar un expediente con hoteles y
 * volver a subirlo dejaba las 66 habitaciones sin nadie dentro, porque la columna se ignoraba y el
 * importador sincroniza. Un test de ida y vuelta lo habría dicho en el momento.
 */
final class PadronFormatoEjeTest extends TestCase
{
    /**
     * @return iterable<string, array{GrupoTipoEnum, ?string}>
     */
    public static function ejes(): iterable
    {
        foreach (GrupoTipoEnum::cases() as $tipo) {
            yield $tipo->value.' sin tramo' => [$tipo, null];
            yield $tipo->value.' con tramo' => [$tipo, 'Occidental Caribe'];
        }
    }

    /**
     * Ida y vuelta sobre TODOS los ejes, no sólo los de hoy: un eje nuevo entra solo en el
     * proveedor y este test le exige lo mismo sin que nadie se acuerde de venir aquí.
     */
    #[DataProvider('ejes')]
    public function testLaCabeceraQueSeEscribeSeVuelveALeer(GrupoTipoEnum $tipo, ?string $subeje): void
    {
        $cabecera = PadronFormato::cabeceraDeEje($tipo, $subeje);
        $leido = PadronFormato::ejeDe($cabecera);

        self::assertNotNull($leido, sprintf('La exportación escribe «%s» y la importación no sabe leerlo.', $cabecera));
        self::assertSame($tipo, $leido['tipo']);
        self::assertSame($subeje, $leido['subeje']);
    }

    public function testElHotelDeUnaHabitacionEsElTramo(): void
    {
        self::assertSame(
            ['tipo' => GrupoTipoEnum::HABITACION, 'subeje' => 'Occidental Caribe'],
            PadronFormato::ejeDe('#Habitación Occidental Caribe'),
        );
    }

    /** Los alias siguen valiendo, y también con tramo: `#Reserva aérea Ida` es `#Vuelo Ida`. */
    public function testLosAliasTambienAdmitenTramo(): void
    {
        self::assertSame(
            PadronFormato::ejeDe('#Vuelo Ida'),
            PadronFormato::ejeDe('#Reserva aérea Ida'),
        );
    }

    /** Sin tildes y en minúsculas también: el padrón viene de la plantilla del colegio. */
    public function testSeLeeSinTildesYEnMinusculas(): void
    {
        self::assertSame(
            ['tipo' => GrupoTipoEnum::HABITACION, 'subeje' => 'Marriott Punta Cana'],
            PadronFormato::ejeDe('#habitacion Marriott Punta Cana'),
        );
    }

    /**
     * ⚠️ Un eje que no existe se sigue DENUNCIANDO. Abrir el tramo a todos los ejes no puede
     * convertir `#Bus` en un grupo fantasma: eso era lo que protegía el diseño anterior y es lo
     * único suyo que había que conservar.
     */
    public function testUnEjeInventadoSigueSiendoNulo(): void
    {
        self::assertNull(PadronFormato::ejeDe('#Bus'));
        self::assertNull(PadronFormato::ejeDe('#Bus Turístico'));
        self::assertNull(PadronFormato::ejeDe('#Notas del coordinador'));
    }
}
