<?php

declare(strict_types=1);

namespace App\Tests\Pms\Service\Tarifa;

use App\Pms\Service\Tarifa\Engine\TarifaDailyPriceFlattener;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class TarifaDailyPriceFlattenerTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $rangos
     * @return array<string, array{price: float, minStay: int, currency: ?string, sourceId: string}>
     */
    private function aplanar(array $rangos, ?callable $fallback = null): array
    {
        return (new TarifaDailyPriceFlattener())->flatten(
            $rangos,
            new DateTimeImmutable('2026-10-01'),
            new DateTimeImmutable('2026-10-03'),
            static fn (array $r): array => $r,
            null,
            $fallback,
        );
    }

    public function testUnImporteEnTextoSeLeeComoNumero(): void
    {
        $dias = $this->aplanar([[
            'start' => new DateTimeImmutable('2026-10-01'),
            'end' => new DateTimeImmutable('2026-10-03'),
            'price' => '120.50',
            'minStay' => '3',
            'currency' => 'USD',
            'id' => 7,
        ]]);

        self::assertSame(120.5, $dias['2026-10-01']['price']);
        self::assertSame(3, $dias['2026-10-01']['minStay']);
        self::assertSame('USD', $dias['2026-10-01']['currency']);
        self::assertSame('id:7', $dias['2026-10-01']['sourceId']);
    }

    /**
     * Antes `(float) 'abc'` era 0.00: el día salía a la venta gratis. Ahora el rango no cuenta y
     * el día lo rellena la tarifa base, como si el precio no estuviera.
     */
    public function testUnPrecioQueNoEsNumeroNoPoneElDiaACero(): void
    {
        $dias = $this->aplanar(
            [[
                'start' => new DateTimeImmutable('2026-10-01'),
                'end' => new DateTimeImmutable('2026-10-03'),
                'price' => 'abc',
            ]],
            static fn (): array => ['price' => '80.00', 'currency' => 'PEN'],
        );

        self::assertSame(80.0, $dias['2026-10-01']['price']);
        self::assertSame('base', $dias['2026-10-01']['sourceId']);
        self::assertSame(2, $dias['2026-10-01']['minStay']);
    }

    public function testUnaTarifaBaseSinPrecioLegibleDejaElDiaSinPrecio(): void
    {
        self::assertSame([], $this->aplanar([], static fn (): array => ['price' => 'n/a']));
    }

    /**
     * El `sourceId` sin id es un hash de los datos: si cambiara para los mismos datos, el mismo
     * rango parecería otro. Fijado con el valor que daba el código antes del nivel 9.
     */
    public function testElHashDeUnRangoSinIdNoCambia(): void
    {
        $dias = $this->aplanar([[
            'start' => new DateTimeImmutable('2026-10-01'),
            'end' => new DateTimeImmutable('2026-10-03'),
            'price' => '120.50',
            'minStay' => 2,
            'currency' => 'USD',
            'weight' => 5,
        ]]);

        $esperado = 'h:' . sha1((string) json_encode([
            'start' => '2026-10-01',
            'end' => '2026-10-03',
            'price' => '120.5',
            'minStay' => '2',
            'currency' => 'USD',
            'important' => '0',
            'weight' => '5',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        self::assertSame($esperado, $dias['2026-10-01']['sourceId']);
    }
}
