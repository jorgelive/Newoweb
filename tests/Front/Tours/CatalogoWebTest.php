<?php

declare(strict_types=1);

namespace App\Tests\Front\Tours;

use App\Front\Comun\Service\TextoI18n;
use App\Front\Tours\Dto\PrecioDesdeWeb;
use App\Front\Tours\Service\CatalogoWebLector;
use PHPUnit\Framework\TestCase;

/**
 * Las piezas puras de la web de tours (docs/WebPublica.md §2 y §4): sin contenedor ni base.
 */
final class CatalogoWebTest extends TestCase
{
    public function testElTextoCaeAlEspanolYLuegoAlPrimeroConTexto(): void
    {
        $i18n = [
            ['language' => 'es', 'content' => 'Montaña de Colores'],
            ['language' => 'en', 'content' => 'Rainbow Mountain'],
            ['language' => 'fr', 'content' => '   '],
        ];

        self::assertSame('Rainbow Mountain', TextoI18n::en($i18n, 'en'));
        // Una traducción vacía no cuenta: se cae al español, no a una tarjeta sin título.
        self::assertSame('Montaña de Colores', TextoI18n::en($i18n, 'fr'));
        self::assertSame('Rainbow Mountain', TextoI18n::en([['language' => 'en', 'content' => 'Rainbow Mountain']], 'de'));
        self::assertNull(TextoI18n::en([], 'es'));
        // La columna es JSON: puede traer el literal `null` o basura.
        self::assertNull(TextoI18n::en(null, 'es'));
        self::assertNull(TextoI18n::en([['language' => 'es']], 'es'));
    }

    public function testElSlugDelTourEsAsciiEnMinusculas(): void
    {
        self::assertSame('montana-de-colores', CatalogoWebLector::slug('Montaña de Colores'));
        self::assertSame('valle-sagrado-tradicional', CatalogoWebLector::slug('  Valle Sagrado  Tradicional '));
        // Un título sin letras no deja la URL en «3-».
        self::assertSame('tour', CatalogoWebLector::slug('¡¿?!'));
    }

    public function testElPrecioSeFormateaSinDecimalesCuandoSonCero(): void
    {
        self::assertSame('S/ 69', (new PrecioDesdeWeb(null, '69', 'PEN'))->formateado());
        self::assertSame('S/ 119', (new PrecioDesdeWeb(null, '119.00', 'PEN'))->formateado());
        self::assertSame('US$ 1,250.50', (new PrecioDesdeWeb(null, '1250.5', 'usd'))->formateado());
    }

}
