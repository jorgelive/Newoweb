<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Dto;

use App\Cotizacion\Dto\CuerpoDeVuelo;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class CuerpoDeVueloTest extends TestCase
{
    public function testLoQueNoVieneNoSeToca(): void
    {
        $c = CuerpoDeVuelo::fromArray(['origen' => 'lim']);

        self::assertTrue($c->trae('origen'));
        self::assertSame('LIM', $c->origen);
        self::assertFalse($c->trae('destino'));
        self::assertFalse($c->trae('salida'));
        self::assertFalse($c->trae('numero'));
    }

    /** PATCH: mandar el campo vacío es vaciarlo, y eso no es lo mismo que no mandarlo. */
    public function testLoQueVieneVacioSeVacia(): void
    {
        $c = CuerpoDeVuelo::fromArray(['aerolinea' => '  ', 'llegada' => '', 'salida' => null]);

        self::assertTrue($c->trae('aerolinea'));
        self::assertNull($c->aerolinea);
        self::assertTrue($c->trae('llegada'));
        self::assertNull($c->llegada);
        self::assertTrue($c->trae('salida'));
        self::assertNull($c->salida);
    }

    /** `numero: null` era «no lo toques» (`isset`), no «déjalo vacío». */
    public function testUnNumeroNuloNoCuentaComoEnviado(): void
    {
        self::assertFalse(CuerpoDeVuelo::fromArray(['numero' => null])->trae('numero'));

        $c = CuerpoDeVuelo::fromArray(['numero' => ' JA7013 ']);
        self::assertTrue($c->trae('numero'));
        self::assertSame('JA7013', $c->numero);
    }

    public function testUnaFechaQueNoSeEntiendeNoVaciaLaQueHabia(): void
    {
        self::assertFalse(CuerpoDeVuelo::fromArray(['salida' => 'mañana temprano'])->salida);
        self::assertFalse(CuerpoDeVuelo::fromArray(['salida' => ['2026-09-18']])->salida);

        $salida = CuerpoDeVuelo::fromArray(['salida' => '2026-09-18 03:00'])->salida;
        self::assertInstanceOf(DateTimeImmutable::class, $salida);
        self::assertSame('2026-09-18 03:00', $salida->format('Y-m-d H:i'));
    }
}
