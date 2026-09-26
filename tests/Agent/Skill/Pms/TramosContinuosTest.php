<?php

declare(strict_types=1);

namespace App\Tests\Agent\Skill\Pms;

use App\Agent\Skill\Pms\AjustarTarifasSkill;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Cómo se agrupan en tramos las noches que se van a reajustar.
 *
 * Cada tramo acaba siendo un `PmsTarifaRango`, así que un tramo mal cortado es una tarifa mal
 * puesta. Se reescribió el 26/09/2026 para que no pasara por variables nulas (subida a PHPStan
 * nivel 8), y esto fija que devuelva lo mismo que antes.
 */
#[CoversClass(AjustarTarifasSkill::class)]
final class TramosContinuosTest extends TestCase
{
    /**
     * @param list<string>                          $noches
     * @param list<array{0: string, 1: string}>     $esperado
     */
    #[DataProvider('casos')]
    public function testAgrupaNochesSeguidas(array $noches, array $esperado): void
    {
        // Sin constructor: el método es puro y no toca ninguna dependencia.
        $skill = (new ReflectionClass(AjustarTarifasSkill::class))->newInstanceWithoutConstructor();
        $metodo = new ReflectionMethod(AjustarTarifasSkill::class, 'tramosContinuos');

        self::assertSame($esperado, $metodo->invoke($skill, $noches));
    }

    /** @return iterable<string, array{list<string>, list<array{0: string, 1: string}>}> */
    public static function casos(): iterable
    {
        yield 'ninguna noche' => [[], []];
        yield 'una noche' => [['2026-10-05'], [['2026-10-05', '2026-10-05']]];
        yield 'tres seguidas' => [
            ['2026-10-05', '2026-10-06', '2026-10-07'],
            [['2026-10-05', '2026-10-07']],
        ];
        yield 'un hueco parte en dos' => [
            ['2026-10-05', '2026-10-06', '2026-10-09', '2026-10-10'],
            [['2026-10-05', '2026-10-06'], ['2026-10-09', '2026-10-10']],
        ];
        yield 'desordenadas se ordenan' => [
            ['2026-10-09', '2026-10-05', '2026-10-06'],
            [['2026-10-05', '2026-10-06'], ['2026-10-09', '2026-10-09']],
        ];
        yield 'cruza de mes' => [
            ['2026-10-31', '2026-11-01'],
            [['2026-10-31', '2026-11-01']],
        ];
    }
}
