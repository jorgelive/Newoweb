<?php

declare(strict_types=1);

namespace App\Tests\Message\Service\Formato;

use App\Message\Service\Formato\HidratadorDeMarcadores;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HidratadorDeMarcadores::class)]
final class HidratadorDeMarcadoresTest extends TestCase
{
    /** La regla de la clase: existe con valor → el valor; existe vacía → nada; no existe → crudo. */
    public function testLaReglaDeLosTresCasos(): void
    {
        $texto = (new HidratadorDeMarcadores())->hidratar(
            'Hola {{ nombre }}{{bloque_pago}}, {{ falta }}',
            ['nombre' => 'Ana', 'bloque_pago' => null],
        );

        self::assertSame('Hola Ana, {{ falta }}', $texto);
    }

    /** Lo que tiene forma de texto se escribe como el `(string)` de siempre. */
    public function testComoTextoEsElCastParaLoQueTieneFormaDeTexto(): void
    {
        self::assertSame('3', HidratadorDeMarcadores::comoTexto(3));
        self::assertSame('60.5', HidratadorDeMarcadores::comoTexto(60.5));
        self::assertSame('', HidratadorDeMarcadores::comoTexto(null));
        self::assertSame('1', HidratadorDeMarcadores::comoTexto(true));
    }

    /** Una lista salía como la palabra «Array» en el mensaje del huésped. */
    public function testUnaListaNoSeEscribe(): void
    {
        self::assertSame('', HidratadorDeMarcadores::comoTexto(['a', 'b']));
    }
}
