<?php

declare(strict_types=1);

namespace App\Tests\Service\Translate;

use App\Service\Translate\GoogleTranslateService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Lo que se le quita al texto antes de mandarlo a Google: el espacio al final de una línea, que
 * le hace comerse la línea siguiente entera. Ver `GoogleTranslateService::sinEspaciosAntesDelSalto()`.
 */
#[CoversClass(GoogleTranslateService::class)]
final class EspaciosAntesDelSaltoTest extends TestCase
{
    public function testElMensajeRealDeLaHuespedQuedaConSusDosLineas(): void
    {
        $real = "Ce serait pour les laisser jeudi matin jusque samedi soir \nOn part jeudi matin tôt vers 6h30 du matin";

        self::assertSame(
            ["Ce serait pour les laisser jeudi matin jusque samedi soir\nOn part jeudi matin tôt vers 6h30 du matin"],
            GoogleTranslateService::sinEspaciosAntesDelSalto([$real]),
        );
    }

    /** Tabuladores, varios espacios, el no separable de WhatsApp y los saltos de Windows. */
    public function testCualquierEspacioHorizontalYCualquierSalto(): void
    {
        self::assertSame(
            ["uno\ndos\r\ntres\n\ncuatro"],
            GoogleTranslateService::sinEspaciosAntesDelSalto(["uno \t \ndos\u{00A0}\r\ntres  \n\ncuatro"]),
        );
    }

    /** Lo de DENTRO de la línea es del texto, y el espacio final sin salto detrás no molesta. */
    public function testNoTocaNadaMas(): void
    {
        $texto = '  Hola,   ¿cómo  estás? ';

        self::assertSame([$texto], GoogleTranslateService::sinEspaciosAntesDelSalto([$texto]));
    }

    public function testEnHtmlNoSeToca(): void
    {
        $html = "<p>Hola </p>\n<p>adiós</p>";

        self::assertSame([$html], GoogleTranslateService::sinEspaciosAntesDelSalto([$html], 'text/html'));
    }
}
