<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Service\Padron;

use App\Cotizacion\Service\Padron\PadronFormato;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PHPUnit\Framework\TestCase;

/**
 * La hoja se lee sin formato: cada celda llega con el tipo que Excel le guardó.
 */
final class PadronFormatoCeldaTest extends TestCase
{
    public function testUnEscalarDaLoMismoQueElCastDeAntes(): void
    {
        self::assertSame('Ana', PadronFormato::celda('Ana'));
        self::assertSame('12345678', PadronFormato::celda(12345678));
        self::assertSame('1', PadronFormato::celda(true));
        self::assertSame('', PadronFormato::celda(null));
        self::assertSame(' con espacios ', PadronFormato::celda(' con espacios '));
    }

    public function testUnaCabeceraConFormatoSeLeePorSuTexto(): void
    {
        $rico = new RichText();
        $rico->createText('Nombres');

        self::assertSame('Nombres', PadronFormato::celda($rico));
    }

    public function testLoQueNoEsUnaCeldaEsVacio(): void
    {
        self::assertSame('', PadronFormato::celda(['matricial']));
    }

    /**
     * Un `1` tecleado en Excel es un NÚMERO. Antes llegaba tal cual a `participa(?string)` y, con
     * `strict_types`, era un `TypeError` que tumbaba la importación entera.
     */
    public function testUnUnoNumericoEsQueParticipa(): void
    {
        self::assertTrue(PadronFormato::participa(PadronFormato::celda(1)));
        self::assertFalse(PadronFormato::participa(PadronFormato::celda(0)));
        self::assertFalse(PadronFormato::participa(PadronFormato::celda(null)));
    }
}
