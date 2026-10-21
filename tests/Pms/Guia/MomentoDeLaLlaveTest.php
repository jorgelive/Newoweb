<?php

declare(strict_types=1);

namespace App\Tests\Pms\Guia;

use App\Pms\Guia\PmsGuiaContexto;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * «No encuentro mi llave» se valida en la LLEGADA: el día de entrada y hasta las 07:00 del día
 * siguiente, en hora de pared del establecimiento (Jorge, 20/10/2026).
 */
final class MomentoDeLaLlaveTest extends TestCase
{
    private function momento(string $ahora): string
    {
        $lima = new DateTimeZone('America/Lima');

        return PmsGuiaContexto::momentoDeLaLlave(
            new DateTimeImmutable('2026-10-19 19:00:00'),
            $lima,
            new DateTimeImmutable($ahora, $lima),
        );
    }

    public function testElDiaDeEntradaEstaEnSuLlegada(): void
    {
        self::assertStringStartsWith('ESTÁ EN SU LLEGADA', $this->momento('2026-10-19 20:05'));
        self::assertStringStartsWith('ESTÁ EN SU LLEGADA', $this->momento('2026-10-19 08:00'), 'Todo el día, no sólo desde la hora de entrada.');
    }

    public function testLaMadrugadaSiguienteSigueSiendoLlegadaHastaLasSiete(): void
    {
        self::assertStringStartsWith('ESTÁ EN SU LLEGADA', $this->momento('2026-10-20 06:59'));
        self::assertStringStartsWith('YA ESTÁ ALOJADO', $this->momento('2026-10-20 07:00'));
    }

    public function testAntesDeSuDiaTodaviaNoEsSuEntrada(): void
    {
        self::assertStringStartsWith('TODAVÍA NO ES SU DÍA', $this->momento('2026-10-18 23:59'));
    }

    /** La fecha guardada es hora de pared de Lima aunque el servidor vaya en UTC. */
    public function testSeComparaEnLaZonaDelEstablecimiento(): void
    {
        $lima = new DateTimeZone('America/Lima');
        // 20/10 03:00 en UTC son las 22:00 del 19/10 en Lima: todavía su día de entrada.
        $ahora = (new DateTimeImmutable('2026-10-20 03:00', new DateTimeZone('UTC')))->setTimezone($lima);

        self::assertStringStartsWith(
            'ESTÁ EN SU LLEGADA',
            PmsGuiaContexto::momentoDeLaLlave(new DateTimeImmutable('2026-10-19 14:00:00'), $lima, $ahora),
        );
    }
}
