<?php

declare(strict_types=1);

namespace App\Tests\Service\Translate;

use App\Service\Translate\CorreccionesDeTraduccion;
use PHPUnit\Framework\TestCase;

final class CorreccionesDeTraduccionTest extends TestCase
{
    public function testUnRotuloCortoSaleFijoYConservaSusNumeros(): void
    {
        $c = new CorreccionesDeTraduccion();

        // Sin contexto, Google hizo «Estar» el verbo: «To be 2», «Om 2 te zijn».
        self::assertSame('Sitting area 2', $c->fija('Estar 2', 'en'));
        self::assertSame('Slaapkamer 1 2', $c->fija('Dormitorio 1 2', 'nl'));
        self::assertSame('Cofre', $c->fija('  caja  FUERTE ', 'pt'));
        self::assertNull($c->fija('Estar en Cusco', 'en'));
    }

    public function testCorregirDosVecesEsComoCorregirUna(): void
    {
        $c = new CorreccionesDeTraduccion();

        foreach ($c->todas() as $idioma => $pares) {
            foreach ($pares as $buscar => $reemplazo) {
                // Si el reemplazo contuviera la búsqueda, cada pasada la volvería a aplicar.
                self::assertStringNotContainsString($buscar, $reemplazo, sprintf('[%s] %s', $idioma, $buscar));

                $una = $c->aplicar($idioma, $buscar);
                self::assertSame($una, $c->aplicar($idioma, $una), sprintf('[%s] %s', $idioma, $buscar));
            }
        }
    }

    public function testSoloTocaSuIdioma(): void
    {
        $c = new CorreccionesDeTraduccion();

        self::assertSame('Il possède une seule serrure', $c->aplicar('it', 'Il possède une seule serrure'));
        self::assertSame('Elle a une seule serrure', $c->aplicar('fr', 'Il possède une seule serrure'));
    }
}
