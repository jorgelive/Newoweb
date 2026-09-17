<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Documento;

use App\Agent\Vision\LectorDeImagenInterface;
use App\Cotizacion\Documento\LectorDeEticket;
use PHPUnit\Framework\TestCase;

/**
 * La INTERPRETACIÓN de la lectura, que es la mitad gratis y repetible.
 *
 * 🔑 Se prueba sin tocar el modelo: `interpretar()` recibe el JSON que ya se guardó en
 * `CotizacionFilearchivo::$datosLeidos`, que es exactamente lo que hará en producción cada vez que
 * se afine una regla. Los 87 documentos ya subidos se re-juzgan sin pagar una llamada.
 */
final class LectorDeEticketTest extends TestCase
{
    private function lector(): LectorDeEticket
    {
        // El modelo no se usa en `interpretar()`. Un doble que reviente deja constancia de eso.
        return new LectorDeEticket(new class implements LectorDeImagenInterface {
            public function nombre(): string { return 'falso'; }
            public function estaConfigurado(): bool { return false; }
            public function leer(string $b, string $m, string $i, array $e): array
            {
                throw new \LogicException('interpretar() no debe llamar al modelo');
            }
        });
    }

    /** @return array<string, mixed> */
    private function crudo(array $encima = []): array
    {
        return [...[
            'esEticket' => true, 'codigo' => 'ABC123',
            'pasajeros' => [['nombre' => 'SANTIAGO GOMEZ', 'pasaporte' => 'P1234567', 'nacionalidad' => 'PER']],
            'traeEntrada' => true, 'fechaEntrada' => '2026-09-18', 'vueloEntrada' => 'CM177',
            'traeSalida' => true, 'fechaSalida' => '2026-09-22', 'vueloSalida' => 'CM749',
        ], ...$encima];
    }

    public function testLeeLosCamposDeLasDosSecciones(): void
    {
        $d = $this->lector()->interpretar($this->crudo());

        self::assertSame('ABC123', $d->codigo);
        self::assertSame('P1234567', $d->pasajeros[0]->pasaporte);
        self::assertSame('SANTIAGO GOMEZ', $d->pasajeros[0]->nombre);
        self::assertSame('2026-09-18', $d->fechaEntrada?->format('Y-m-d'));
        self::assertSame('CM749', $d->vueloSalida);
        self::assertTrue($d->traeEntrada);
        self::assertTrue($d->traeSalida);
        self::assertSame([], $d->avisos);
    }

    /**
     * 🔥 **Las 121 lecturas ya pagadas están guardadas con la forma vieja.** `interpretar()` corre
     * sobre lo guardado cada vez que se rejuzga, así que si deja de saber leer lo viejo, afinar una
     * regla vacía el expediente entero de nombres y pasaportes — y sin dar error: saldrían todos
     * como «no hay con qué comprobar a nombre de quién está el trámite».
     */
    public function testUnaLecturaVieja_ConLosCamposSueltos_DaUnaListaDeUno(): void
    {
        $d = $this->lector()->interpretar([
            'esEticket' => true, 'codigo' => 'ABC123',
            'nombres' => 'SANTIAGO', 'apellidos' => 'GOMEZ', 'pasaporte' => 'P1234567',
            'traeEntrada' => true, 'fechaEntrada' => '2026-09-18', 'vueloEntrada' => 'CM177',
            'traeSalida' => true, 'fechaSalida' => '2026-09-22', 'vueloSalida' => 'CM749',
        ]);

        self::assertCount(1, $d->pasajeros);
        self::assertSame('SANTIAGO GOMEZ', $d->pasajeros[0]->nombre);
        self::assertSame('P1234567', $d->pasajeros[0]->pasaporte);
    }

    /** El caso del formulario familiar: una fila por persona, en su orden. */
    public function testLeeLaTablaEnteraDePasajeros(): void
    {
        $d = $this->lector()->interpretar($this->crudo(['pasajeros' => [
            ['nombre' => 'YUSI BETSI CRUZ ALVAREZ', 'pasaporte' => '125995393', 'nacionalidad' => 'PER'],
            ['nombre' => 'HERBERT JESUS ZEVALLOS GUZMAN', 'pasaporte' => '125995436', 'nacionalidad' => 'PER'],
        ]]));

        self::assertCount(2, $d->pasajeros);
        self::assertSame('125995436', $d->pasajeros[1]->pasaporte);
    }

    /**
     * 🔥 Medio grupo sube su billete de avión creyendo que es esto. Un billete tiene número de
     * vuelo y fecha, así que sin esta pregunta cotejaría bien y daría por hecho un trámite que
     * nadie hizo.
     */
    public function testUnBilleteDeAvionNoPasaPorEticket(): void
    {
        $d = $this->lector()->interpretar($this->crudo(['esEticket' => false]));

        self::assertFalse($d->esUtilizable());
        self::assertStringContainsString('no parece un E-Ticket', $d->avisos[0]);
    }

    public function testSoloLaEntradaSeAvisa(): void
    {
        $d = $this->lector()->interpretar($this->crudo([
            'traeSalida' => false, 'fechaSalida' => '', 'vueloSalida' => '',
        ]));

        self::assertContains('sólo trae la ENTRADA: falta rellenar la salida', $d->avisos);
    }

    /**
     * ⚠️ «La sección no está» y «la sección está y no se pudo leer» son cosas distintas, porque lo
     * que hay que hacer es distinto: rehacer el trámite, o mirar el escaneo.
     */
    public function testUnaSeccionIlegibleNoSeConfundeConUnaQueFalta(): void
    {
        $d = $this->lector()->interpretar($this->crudo(['fechaSalida' => '']));

        self::assertTrue($d->traeSalida);
        self::assertContains('la sección de salida está pero no se pudo leer su fecha', $d->avisos);
        self::assertNotContains('sólo trae la ENTRADA: falta rellenar la salida', $d->avisos);
    }

    public function testLaSalidaAnteriorALaEntradaSeAvisa(): void
    {
        $d = $this->lector()->interpretar($this->crudo(['fechaSalida' => '2026-09-10']));

        self::assertContains('la salida es anterior a la entrada', $d->avisos);
    }

    /** Una fecha con formato imposible se queda vacía en vez de convertirse en otra cosa. */
    public function testUnaFechaIlegibleNoSeInventa(): void
    {
        $d = $this->lector()->interpretar($this->crudo(['fechaEntrada' => '18/09/2026']));

        self::assertNull($d->fechaEntrada);
    }

    /** Los vacíos entran como `null` y no como cadena vacía: se comparan distinto. */
    public function testLoVacioEsNuloYNoCadenaVacia(): void
    {
        $d = $this->lector()->interpretar($this->crudo(['pasaporte' => '   ']));

        self::assertNull($d->pasaporte);
    }
}
