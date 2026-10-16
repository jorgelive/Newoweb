<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Service\Publico;

use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionCotservicio;
use App\Cotizacion\Enum\CotizacionEstadoEnum;
use App\Cotizacion\Service\Publico\ComoReservarResolver;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Las decisiones del bloque «Cómo reservar» que no necesitan base de datos: cuándo se enseña,
 * qué día se paga el resto y el porcentaje. Lo que toca Finanzas (qué medios) es
 * `FinMedioCobroRepository::ofrecibles()` con `FinAudienciaCobro::desdePeru()`, probado aparte.
 */
#[CoversClass(ComoReservarResolver::class)]
final class ComoReservarResolverTest extends TestCase
{
    public function testSoloSeOfreceLoQueAunNoSeReservo(): void
    {
        $siOfrece = [CotizacionEstadoEnum::PENDIENTE, CotizacionEstadoEnum::ENVIADO];

        foreach (CotizacionEstadoEnum::cases() as $estado) {
            self::assertSame(
                \in_array($estado, $siOfrece, true),
                ComoReservarResolver::seOfreceEnEstado($estado),
                // A quien ya pagó, «reserva con el 50 %» le invita a pagar dos veces.
                sprintf('Estado %s', $estado->value),
            );
        }
    }

    public function testElRestoSePagaElDiaDelPrimerServicio(): void
    {
        $cotizacion = new Cotizacion();
        $cotizacion->addCotservicio($this->servicio('2026-11-14 09:00'));
        $cotizacion->addCotservicio($this->servicio('2026-11-12 18:30'));
        $cotizacion->addCotservicio(new CotizacionCotservicio()); // sin fecha: no cuenta

        self::assertSame('2026-11-12 00:00', ComoReservarResolver::primerServicio($cotizacion)?->format('Y-m-d H:i'));
    }

    public function testSinFechasNoHayDia(): void
    {
        self::assertNull(ComoReservarResolver::primerServicio(new Cotizacion()));
    }

    public function testElAdelantoEsUnPorcentajeAcotado(): void
    {
        $cotizacion = new Cotizacion();
        self::assertSame(50.0, $cotizacion->porcentajeDeAdelanto(), 'El 50 de siempre');

        $cotizacion->setAdelanto('130.00');
        self::assertSame(100.0, $cotizacion->porcentajeDeAdelanto());

        $cotizacion->setAdelanto('-5');
        self::assertSame(0.0, $cotizacion->porcentajeDeAdelanto());
    }

    private function servicio(string $inicio): CotizacionCotservicio
    {
        return (new CotizacionCotservicio())->setFechaInicioAbsoluta(new DateTimeImmutable($inicio));
    }
}
