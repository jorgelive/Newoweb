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
}
