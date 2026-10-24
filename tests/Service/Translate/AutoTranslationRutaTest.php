<?php

declare(strict_types=1);

namespace App\Tests\Service\Translate;

use App\Service\Translate\AutoTranslationService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Las dos lecturas de forma del servicio de autotraducción, sin traductor: sólo deciden si la
 * estructura se puede recorrer. Por reflexión porque son privadas y no merecen dejar de serlo.
 */
final class AutoTranslationRutaTest extends TestCase
{
    private function llamar(string $metodo, mixed ...$args): mixed
    {
        $clase = new ReflectionClass(AutoTranslationService::class);
        $servicio = $clase->newInstanceWithoutConstructor();

        return $clase->getMethod($metodo)->invoke($servicio, ...$args);
    }

    public function testUnNivelQueNoEsObjetoNiListaFallaConSuRuta(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bloques.0.detalle');

        $this->llamar('nivel', 'texto suelto', 'bloques.0.detalle');
    }

    public function testUnNivelQueEsArraySePasaTalCual(): void
    {
        self::assertSame(['a' => 1], $this->llamar('nivel', ['a' => 1], 'x'));
    }

    public function testUnIdiomaQueNoEsTextoNoSeConvierteEnLaClaveArray(): void
    {
        $this->expectException(RuntimeException::class);

        $this->llamar('listToMapRows', [['language' => ['es'], 'content' => 'x']], 'titulo');
    }

    public function testElMapaSeIndexaPorIdiomaEnMinusculas(): void
    {
        $mapa = $this->llamar('listToMapRows', [['language' => 'ES', 'content' => null]], 'titulo');

        self::assertSame(['es' => ['language' => 'ES', 'content' => '']], $mapa);
    }

    /**
     * Lo que deja el servicio de un texto, sin llamar al traductor: el origen no es utilizable.
     *
     * @param array<string, array<string, string>> $mapa
     *
     * @return array<string, mixed>
     */
    private function sinOrigen(array $mapa, bool $vetado = false, bool $soloSellar = false): array
    {
        $clase = new ReflectionClass(AutoTranslationService::class);
        $servicio = $clase->newInstanceWithoutConstructor();
        $clase->getProperty('validLanguageCodes')->setValue($servicio, ['es', 'en', 'fr']);

        $resultado = $clase->getMethod('translateAndCloneRows')->invoke($servicio, $mapa, 'es', 'text', false, $vetado, $soloSellar);
        self::assertIsArray($resultado);

        /** @var array<string, mixed> $resultado */
        return $resultado;
    }

    public function testUnEspanolBorradoSeLlevaLasTraduccionesQueSalieronDeEl(): void
    {
        $resultado = $this->sinOrigen([
            'es' => ['language' => 'es', 'content' => ''],
            'en' => ['language' => 'en', 'content' => '1', 'origenHash' => 'abc'],
            'fr' => ['language' => 'fr', 'content' => 'Écrit à la main'],
            'it' => ['language' => 'it', 'content' => 'Curata', 'origenHash' => 'manual'],
        ]);

        self::assertSame(['es', 'fr', 'it'], array_keys($resultado), 'la automática se va; la escrita a mano y la curada, no');
    }

    public function testUnEspanolQueFaltaNoTocaNada(): void
    {
        $mapa = ['en' => ['language' => 'en', 'content' => '1', 'origenHash' => 'abc']];

        self::assertSame($mapa, $this->sinOrigen($mapa));
    }

    public function testUnParrafoHtmlVaciadoCuentaComoBorrado(): void
    {
        $resultado = $this->sinOrigen([
            'es' => ['language' => 'es', 'content' => '<p><br></p>'],
            'en' => ['language' => 'en', 'content' => '<p>Hi</p>', 'origenHash' => 'abc'],
        ]);

        self::assertSame(['es'], array_keys($resultado));
    }

    public function testConVetoNoSeBorraNada(): void
    {
        $mapa = [
            'es' => ['language' => 'es', 'content' => ''],
            'en' => ['language' => 'en', 'content' => 'Hi', 'origenHash' => 'abc'],
        ];

        self::assertSame($mapa, $this->sinOrigen($mapa, vetado: true));
    }

    public function testSellandoNoSeBorraNada(): void
    {
        $mapa = [
            'es' => ['language' => 'es', 'content' => ''],
            'en' => ['language' => 'en', 'content' => 'Hi', 'origenHash' => 'abc'],
        ];

        self::assertSame($mapa, $this->sinOrigen($mapa, soloSellar: true));
    }
}
