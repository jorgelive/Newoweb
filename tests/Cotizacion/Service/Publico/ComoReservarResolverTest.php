<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Service\Publico;

use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionCotservicio;
use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionPago;
use App\Cotizacion\Enum\CotizacionEstadoEnum;
use App\Cotizacion\Enum\FileEstadoEnum;
use App\Cotizacion\Enum\FileModoEnum;
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
        $file = new CotizacionFile();

        foreach (CotizacionEstadoEnum::cases() as $estado) {
            self::assertSame(
                \in_array($estado, $siOfrece, true),
                ComoReservarResolver::seOfreceEnEstado($file, $estado),
                // A quien ya pagó, «reserva con el 50 %» le invita a pagar dos veces.
                sprintf('Estado %s', $estado->value),
            );
        }
    }

    /** Hallazgo M3 de la revisión: confirmar la v2 no despublica la v1, que seguía ofreciéndose. */
    public function testConUnaHermanaVendidaNoSeOfreceNinguna(): void
    {
        $file = new CotizacionFile();
        $v1 = (new Cotizacion())->setPropuesta(1)->setEstado(CotizacionEstadoEnum::ENVIADO);
        $file->addCotizacion($v1);
        self::assertTrue(ComoReservarResolver::seOfrece($file, $v1));

        $file->addCotizacion((new Cotizacion())->setPropuesta(2)->setEstado(CotizacionEstadoEnum::CONFIRMADO));
        self::assertFalse(ComoReservarResolver::seOfrece($file, $v1));
    }

    public function testUnExpedienteCerradoOArchivadoNoOfreceNada(): void
    {
        foreach ([FileEstadoEnum::CERRADO, FileEstadoEnum::ARCHIVADO] as $estado) {
            $file = (new CotizacionFile())->setEstado($estado);
            self::assertFalse(ComoReservarResolver::seOfreceEnEstado($file, CotizacionEstadoEnum::ENVIADO));
        }
    }

    public function testEnUnGrupoNoSeEnsenaLoPagado(): void
    {
        $file = (new CotizacionFile())->setModo(FileModoEnum::GRUPO);
        $file->addCotizacion((new Cotizacion())->setPropuesta(1)->setEstado(CotizacionEstadoEnum::ENVIADO)->setTotalVenta('900'));
        $file->getPagos()->add((new CotizacionPago())->setFile($file)->setMonto('300')->setMoneda('USD'));

        self::assertSame(['usd' => 0.0, 'porMoneda' => []], ComoReservarResolver::pagadoVisible($file, 1, false));
    }

    /**
     * Revisión del 16/10/2026: con el precio oculto, el detalle seguía mandando lo pagado. La
     * portada ya lo tapaba; el bloque del detalle cuelga del expediente, así que el redactor de
     * montos de la cotización no llegaba hasta él.
     */
    public function testConElPrecioOcultoTampocoSeEnsenaLoPagado(): void
    {
        $file = (new CotizacionFile())->setModo(FileModoEnum::ESTANDAR);
        $file->addCotizacion((new Cotizacion())->setPropuesta(1)->setEstado(CotizacionEstadoEnum::ENVIADO)->setTotalVenta('900'));
        $file->getPagos()->add((new CotizacionPago())->setFile($file)->setMonto('300')->setMoneda('USD')->setPropuesta(1));

        self::assertSame(300.0, ComoReservarResolver::pagadoVisible($file, 1, false)['usd'], 'Con precio a la vista, lo pagado se dice');
        self::assertSame(['usd' => 0.0, 'porMoneda' => []], ComoReservarResolver::pagadoVisible($file, 1, true));
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
