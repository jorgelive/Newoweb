<?php

declare(strict_types=1);

namespace App\Tests\Dto;

use App\Dto\Lee;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * El lector de frontera: lo que no es del tipo esperado es «no llegó». Ver `docs/TiposDeFrontera.md`.
 */
#[CoversClass(Lee::class)]
final class LeeTest extends TestCase
{
    /** @return iterable<string, array{mixed, ?bool}> */
    public static function booleanos(): iterable
    {
        yield 'true' => [true, true];
        yield 'false' => [false, false];
        yield '"false" en texto' => ['false', false];
        yield '"1"' => ['1', true];
        yield '0' => [0, false];
        // 🔥 Estos tres son el fallo que casi se despliega: `filter_var(null)` da `false`.
        yield 'ausente' => [null, null];
        yield 'vacío' => ['', null];
        yield 'array' => [['x'], null];
        yield 'basura' => ['quizá', null];
    }

    #[DataProvider('booleanos')]
    public function testBooleano(mixed $valor, ?bool $esperado): void
    {
        self::assertSame($esperado, Lee::booleano($valor));
    }

    public function testTextoNoConvierteUnArrayEnLaPalabraArray(): void
    {
        self::assertNull(Lee::texto(['a']));
        self::assertSame('12.5', Lee::texto(12.5));
        self::assertSame(' a ', Lee::texto(' a '), 'texto() no recorta');
        self::assertNull(Lee::textoLimpio('   '), 'textoLimpio() sí, y el vacío es null');
    }

    public function testEnteroAceptaElNumeroEnTextoPeroNoUnDecimal(): void
    {
        self::assertSame(1727312345, Lee::entero('1727312345'));
        self::assertSame(5, Lee::entero(5.0));
        self::assertNull(Lee::entero('5.5'));
        self::assertNull(Lee::entero('abc'));
    }

    public function testEnRecorreSinAvisarDeLoQueFalta(): void
    {
        $d = ['a' => ['b' => [0 => ['c' => 'ok']]], 'x' => 'texto'];

        self::assertSame('ok', Lee::en($d, 'a', 'b', 0, 'c'));
        self::assertNull(Lee::en($d, 'a', 'z', 'c'));
        self::assertNull(Lee::en($d, 'x', 'y'), 'un texto no es un mapa por el que seguir');
    }

    /** Lo que se publica con tipo: sólo claves de texto, para que el esquema diga «objeto». */
    public function testObjetoSoloGuardaLasClavesDeTexto(): void
    {
        self::assertSame(['url' => 'a.jpg'], Lee::objeto(['url' => 'a.jpg', 0 => 'suelto']));
        self::assertSame([], Lee::objeto('texto'));
    }
}
