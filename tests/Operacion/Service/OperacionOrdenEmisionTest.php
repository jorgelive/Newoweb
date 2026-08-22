<?php

declare(strict_types=1);

namespace App\Tests\Operacion\Service;

use App\Cotizacion\Entity\CotizacionCottarifa;
use App\Cotizacion\Entity\CotizacionFile;
use App\Operacion\Entity\OperacionOrdenServicio;
use App\Operacion\Entity\OperacionServicio;
use App\Cotizacion\Enum\ComponenteEstadoEnum;
use App\Operacion\Enum\EstadoOrdenServicioEnum;
use App\Cotizacion\Service\CadenaDeAlojamientoBuilder;
use App\Cotizacion\Service\CotizacionPuntosDelServicio;
use App\Operacion\Service\OperacionOrdenEmision;
use App\Operacion\Service\OperacionPuntosDelServicio;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Las reglas de coherencia de una Orden y su máquina de estados.
 *
 * Vivían repartidas —`conflictoSeleccion` en el navegador y guardas sueltas en los setters— y
 * por eso dos pestañas abiertas podían armar lo que la vista impedía. Al juntarlas en
 * `validar()` se pueden probar sin contenedor ni base.
 */
final class OperacionOrdenEmisionTest extends TestCase
{

    /** La emisión con su resolvedor de puntos real, sin base de datos. Ver el test de divergencia. */
    private function emision(): OperacionOrdenEmision
    {
        $em = $this->createStub(EntityManagerInterface::class);

        return new OperacionOrdenEmision(new OperacionPuntosDelServicio(
            new CotizacionPuntosDelServicio($em),
            new CadenaDeAlojamientoBuilder($em),
        ));
    }
    private OperacionOrdenEmision $emision;

    protected function setUp(): void
    {
        $this->emision = $this->emision();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // VALIDAR — qué puede entrar en una orden
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function sinServiciosNoHayOrden(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Elige al menos un servicio');

        $this->emision->validar([]);
    }

    #[Test]
    public function dosServiciosDelMismoExpedienteYCompradorPasan(): void
    {
        $file = new CotizacionFile();
        $servicios = [
            $this->servicio('Traslado', $file, 'openperu-tickets'),
            $this->servicio('Tren', $file, 'openperu-tickets'),
        ];

        $this->emision->validar($servicios);

        $this->addToAssertionCount(1);   // no lanzó: es lo que se comprueba
    }

    /** Un proveedor no puede firmar un documento que mezcla dos viajes. */
    #[Test]
    public function expedientesDistintosSeRechazan(): void
    {
        $servicios = [
            $this->servicio('Traslado', (new CotizacionFile())->setNombreGrupo('Nune & Todd'), 'x'),
            $this->servicio('Tren', (new CotizacionFile())->setNombreGrupo('Pasquali'), 'x'),
        ];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('expedientes distintos');

        $this->emision->validar($servicios);
    }

    /**
     * Por ID y no por texto: «Futurismo» tecleado y «Futurismo Jonathan» del catálogo serían
     * dos órdenes distintas aunque se lean igual.
     */
    #[Test]
    public function compradoresDistintosSeRechazan(): void
    {
        $file = new CotizacionFile();
        $servicios = [
            $this->servicio('Traslado', $file, 'openperu-tickets'),
            $this->servicio('Tren', $file, 'futurismo'),
        ];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('compradores distintos');

        $this->emision->validar($servicios);
    }

    #[Test]
    public function loQueNoSeCompraSeRechaza(): void
    {
        // Sin tarifa: está en La Biblia sólo como referencia.
        $referencia = (new OperacionServicio())->setDescripcionServicio('Hotel del pasajero');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('no puede entrar en una Orden de Servicio');

        $this->emision->validar([$referencia]);
    }

    /** El caso de «salvo hayan sido cancelados»: la guarda que ya existía basta. */
    #[Test]
    public function loCanceladoEnLaCotizacionNoVuelveAEntrar(): void
    {
        $cancelado = $this->servicio('Traslado', new CotizacionFile(), 'x')
            ->setEstadoComponente(ComponenteEstadoEnum::CANCELADO->value);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('cancelado en la cotización');

        $this->emision->validar([$cancelado]);
    }

    #[Test]
    public function unServicioYaEnOtraOrdenSeRechaza(): void
    {
        $servicio = $this->servicio('Traslado', new CotizacionFile(), 'x');
        $otra = (new OperacionOrdenServicio())->setNumeroOs('OS-014');
        $servicio->setOrdenServicio($otra);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ya está en la orden OS-014');

        $this->emision->validar([$servicio]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TRANSICIONES — un borrador se compone, una emitida ya es un documento
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    #[DataProvider('transicionesPermitidas')]
    public function transicionPermitida(EstadoOrdenServicioEnum $desde, EstadoOrdenServicioEnum $hasta): void
    {
        $this->emision->validarTransicion($desde, $hasta);

        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{EstadoOrdenServicioEnum, EstadoOrdenServicioEnum}> */
    public static function transicionesPermitidas(): iterable
    {
        yield 'emitir' => [EstadoOrdenServicioEnum::BORRADOR, EstadoOrdenServicioEnum::EMITIDA];
        yield 'confirmar' => [EstadoOrdenServicioEnum::EMITIDA, EstadoOrdenServicioEnum::CONFIRMADA];
        yield 'completar' => [EstadoOrdenServicioEnum::CONFIRMADA, EstadoOrdenServicioEnum::COMPLETADA];
        yield 'anular una emitida' => [EstadoOrdenServicioEnum::EMITIDA, EstadoOrdenServicioEnum::CANCELADA];
        yield 'descartar un borrador' => [EstadoOrdenServicioEnum::BORRADOR, EstadoOrdenServicioEnum::CANCELADA];
        yield 'quedarse igual' => [EstadoOrdenServicioEnum::EMITIDA, EstadoOrdenServicioEnum::EMITIDA];
    }

    /** Una anulada no revive: para eso está reemitir. */
    #[Test]
    public function unaAnuladaNoVuelveAtras(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('no vuelve atrás');

        $this->emision->validarTransicion(EstadoOrdenServicioEnum::CANCELADA, EstadoOrdenServicioEnum::EMITIDA);
    }

    /** Volver a borrador reescribiría algo que el proveedor ya tiene. */
    #[Test]
    public function unaEmitidaNoVuelveABorrador(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ya emitida no vuelve a borrador');

        $this->emision->validarTransicion(EstadoOrdenServicioEnum::EMITIDA, EstadoOrdenServicioEnum::BORRADOR);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function servicio(string $descripcion, CotizacionFile $file, string $compradorId): OperacionServicio
    {
        return (new OperacionServicio())
            ->setDescripcionServicio($descripcion)
            ->setFile($file)
            ->setCompradorMaestroId($compradorId)
            // Sin tarifa sería «sólo referencia» y no se le compra a nadie.
            ->setCotizacionTarifa(new CotizacionCottarifa());
    }
}
