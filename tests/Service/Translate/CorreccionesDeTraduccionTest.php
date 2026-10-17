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

    /** Google deja «<strong>right</strong> .»: el punto se ve suelto en pantalla. */
    public function testQuitaElEspacioEntreLaNegritaYLaPuntuacion(): void
    {
        self::assertSame(
            'hot to the <strong>right</strong>. Fully open the <strong>left</strong>, which is the hot one: wait!',
            CorreccionesDeTraduccion::sinEspacioAntesDePuntuacion('en', 'hot to the <strong>right</strong> . Fully open the <strong>left</strong> , which is the hot one: wait!'),
        );
        self::assertSame(
            'controls the <strong>hot water</strong>: wait',
            CorreccionesDeTraduccion::sinEspacioAntesDePuntuacion('de', 'controls the <strong>hot water</strong> : wait'),
        );
    }

    /** En francés el espacio delante de «:» es su tipografía; delante del punto, no. */
    public function testEnFrancesRespetaElEspacioDelanteDeLosDosPuntos(): void
    {
        self::assertSame(
            'eau chaude à <strong>droite</strong>. Salle de bain <strong>à l\'étage</strong> : eau chaude',
            CorreccionesDeTraduccion::sinEspacioAntesDePuntuacion('fr', 'eau chaude à <strong>droite</strong> . Salle de bain <strong>à l\'étage</strong> : eau chaude'),
        );
    }

    /** Sólo detrás de una etiqueta de cierre: el texto plano y el resto de espacios, intactos. */
    public function testNoTocaNadaMas(): void
    {
        $texto = 'Hola , qué tal ? <p> 👉 Abre</p> <strong>ya</strong> mismo';

        self::assertSame($texto, CorreccionesDeTraduccion::sinEspacioAntesDePuntuacion('en', $texto));
    }
}
