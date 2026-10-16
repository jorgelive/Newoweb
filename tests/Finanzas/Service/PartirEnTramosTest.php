<?php

declare(strict_types=1);

namespace App\Tests\Finanzas\Service;

use App\Finanzas\Service\FinEnlacePagoService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Un cobro que no cabe en el tope de la pasarela se parte en el mínimo de tramos, en céntimos
 * exactos, y ninguno pasa del tope CON la comisión. Culqi: 3000 USD por operación.
 */
#[CoversClass(FinEnlacePagoService::class)]
final class PartirEnTramosTest extends TestCase
{
    public function testSiCabeEsUnSoloEnlace(): void
    {
        self::assertSame(['420.00'], FinEnlacePagoService::partirEnTramos('420', '3000', 5.5));
        self::assertSame(['9999.00'], FinEnlacePagoService::partirEnTramos('9999', null, 5.5), 'Sin tope, no se parte');
    }

    public function testElTopeSeMideConLaComision(): void
    {
        // 2 900 + 5,5 % = 3 059,50: ya no cabe en uno.
        self::assertSame(['1450.00', '1450.00'], FinEnlacePagoService::partirEnTramos('2900', '3000', 5.5));
    }

    public function testLosTramosSumanElNetoAlCentimoYCadaUnoCabe(): void
    {
        $partes = FinEnlacePagoService::partirEnTramos('95373.21', '3000', 5.5);

        self::assertCount(34, $partes);
        self::assertSame(9537321, array_sum(array_map(static fn (string $p): int => (int) round((float) $p * 100), $partes)));
        foreach ($partes as $p) {
            self::assertLessThanOrEqual(3000.0, round((float) $p * 1.055, 2));
        }
        // El céntimo que sobra va a los primeros.
        self::assertGreaterThanOrEqual((float) end($partes), (float) $partes[0]);
    }
}
