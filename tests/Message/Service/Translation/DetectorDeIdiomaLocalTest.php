<?php

declare(strict_types=1);

namespace App\Tests\Message\Service\Translation;

use App\Message\Service\Translation\DetectorDeIdiomaLocal;
use App\Message\Service\Translation\LongitudParaIdioma;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * El idioma de un mensaje entrante: sólo entre los del maestro, y lo dudoso conserva el actual.
 * Los textos son mensajes reales que el detector anterior pasaba a otro idioma (o a inglés).
 */
#[CoversClass(DetectorDeIdiomaLocal::class)]
#[CoversClass(LongitudParaIdioma::class)]
final class DetectorDeIdiomaLocalTest extends TestCase
{
    private const array MAESTRO = ['ar', 'bg', 'cs', 'da', 'de', 'el', 'en', 'es', 'fi', 'fr', 'he', 'hi', 'hr', 'hu', 'id', 'it', 'ja', 'ko', 'ms', 'nl', 'no', 'pl', 'pt', 'ro', 'ru', 'sk', 'sl', 'sv', 'th', 'tr', 'uk', 'vi', 'zh'];

    /** El caso que lo destapó: 15 caracteres, dos palabras. El viejo decía chamorro → inglés. */
    public function testParfaitMerciNoLeCambiaElIdiomaAUnaFrancesa(): void
    {
        self::assertSame('fr', $this->detector()->codigoDe('Parfait merci !', 'fr'));
    }

    /** Lo dudoso conserva el ACTUAL, sea el que sea; nunca cae a inglés. */
    public function testLoCortoConservaElIdiomaActual(): void
    {
        foreach (['ok', 'GRACIAS', 'Merci beaucoup !!', '15-16 h', 'Parfait merci !'] as $texto) {
            self::assertSame('es', $this->detector()->codigoDe($texto, 'es'), $texto);
            self::assertSame('de', $this->detector()->codigoDe($texto, 'de'), $texto);
        }
    }

    /** «Muchas gracias Susan» salía gaélico; «Me confirmas porfa», portugués por 0,03. */
    public function testEspañolCortoNoSePasaAOtroIdioma(): void
    {
        foreach (['Muchas gracias Susan', 'Me confirmas porfa por favor', 'Ok ok, muchas gracias'] as $texto) {
            self::assertSame('es', $this->detector()->codigoDe($texto, 'es'), $texto);
        }
    }

    /** Lo que SÍ hay que detectar: un huésped que escribe largo en su idioma en un hilo en español. */
    public function testUnMensajeLargoEnOtroIdiomaSiCambiaElHilo(): void
    {
        self::assertSame('fr', $this->detector()->codigoDe(
            "Bonjour, nous avons eu des tickets pour le Machu Picchu que pour dimanche, donc nous allons devoir annuler la réservation de ce week-end",
            'es',
        ));
        self::assertSame('en', $this->detector()->codigoDe(
            "Hello, we will arrive tomorrow around three in the afternoon. Could you tell us how to get the keys?",
            'es',
        ));
    }

    /** Pase lo que pase, la respuesta es un idioma del maestro (o el actual). */
    public function testNuncaDevuelveUnIdiomaQueNoTenemos(): void
    {
        $detector = new DetectorDeIdiomaLocal(['es', 'en', 'fr', 'pt']);

        foreach (['Thank you! Have a great evening', 'Hi, can I get a secure payment link?', 'Precio de casita 5 dos días', 'Cómo hago para abonar?', 'Hola Susan, veo que se ocupo. tenes otra similar??'] as $texto) {
            self::assertContains($detector->codigoDe($texto, 'es'), ['es', 'en', 'fr', 'pt'], $texto);
        }
    }

    public function testLasVariantesDeLaLibreriaSeQuedanConElCodigoDelMaestro(): void
    {
        self::assertSame('pt', DetectorDeIdiomaLocal::aMaestro('pt-BR'));
        self::assertSame('zh', DetectorDeIdiomaLocal::aMaestro('zh-Hant'));
        self::assertSame('el', DetectorDeIdiomaLocal::aMaestro('el-monoton'));
        self::assertSame('no', DetectorDeIdiomaLocal::aMaestro('nb'));
        self::assertSame('no', DetectorDeIdiomaLocal::aMaestro('nn'));

        $codigos = DetectorDeIdiomaLocal::codigosDeLibreriaPara(['pt', 'es']);
        sort($codigos);
        self::assertSame(['es', 'pt-BR', 'pt-PT'], $codigos, 'Ni gallego ni occitano: sólo lo que tenemos');
    }

    public function testLaLongitudPideTresPalabrasYQuinceCaracteres(): void
    {
        self::assertFalse(LongitudParaIdioma::basta('Parfait merci !'), 'Dos palabras');
        self::assertFalse(LongitudParaIdioma::basta('Ok, sí, ya'), 'Tres palabras, pero corto');
        self::assertTrue(LongitudParaIdioma::basta('Muchas gracias Susan'));
        self::assertTrue(LongitudParaIdioma::basta('On va réfléchir demain'), 'Los acentos no parten palabras');
    }

    private function detector(): DetectorDeIdiomaLocal
    {
        return new DetectorDeIdiomaLocal(self::MAESTRO);
    }
}
