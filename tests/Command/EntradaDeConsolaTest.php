<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\EntradaDeConsola;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Exception\InvalidArgumentException;

final class EntradaDeConsolaTest extends TestCase
{
    public function testUnNumeroEnTextoEsEseNumero(): void
    {
        self::assertSame(25, EntradaDeConsola::entero('25', 'limite'));
        self::assertSame(-3, EntradaDeConsola::entero('-3', 'dias'));
        self::assertSame(7, EntradaDeConsola::entero(7, 'dias'));
    }

    /**
     * El caso que motivó la clase: `(int) 'diez'` era `0`, y el comando seguía como si le hubieran
     * pedido cero.
     */
    public function testLoQueNoEsUnNumeroFallaConElNombreDeLaOpcion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('«limite»');

        EntradaDeConsola::entero('diez', 'limite');
    }

    public function testUnDecimalNoSeTruncaEnSilencio(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EntradaDeConsola::entero('2.5', 'dias');
    }

    public function testLoOpcionalAusenteEsNull(): void
    {
        self::assertNull(EntradaDeConsola::enteroOpcional(null, 'pax'));
        self::assertNull(EntradaDeConsola::textoOpcional(null, 'canal'));
        self::assertSame(4, EntradaDeConsola::enteroOpcional('4', 'pax'));
    }

    public function testElTextoSeDevuelveTalCual(): void
    {
        self::assertSame(' ABC12 ', EntradaDeConsola::texto(' ABC12 ', 'localizador'));
    }

    public function testUnaOpcionSinValorNoPasaPorTexto(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EntradaDeConsola::texto(true, 'canal');
    }

    public function testLaListaDevuelveLosTextos(): void
    {
        self::assertSame(['A', 'B'], EntradaDeConsola::textos(['A', 'B'], 'localizadores'));
        self::assertSame([], EntradaDeConsola::textos([], 'elegir'));
    }
}
