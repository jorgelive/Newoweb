<?php

declare(strict_types=1);

namespace App\Tests\Operacion\Entity;

use App\Cotizacion\Entity\CotizacionCottarifa;
use App\Operacion\Entity\OperacionOrdenServicio;
use App\Operacion\Entity\OperacionOrdenServicioItem;
use App\Operacion\Entity\OperacionServicio;
use App\Operacion\Enum\EstadoOrdenServicioEnum;
use App\Operacion\Service\OperacionOrdenEmision;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Cuándo una Orden emitida se marca sucia, y qué pasa al anularla.
 *
 * Sin contenedor ni base: sólo la lógica de las entidades y el servicio de emisión.
 */
final class OperacionOrdenDivergenciaTest extends TestCase
{
    #[Test]
    public function unBorradorNuncaEstaSucio(): void
    {
        $orden = new OperacionOrdenServicio();

        // Es una vista viva mientras se compone: no hay documento del que separarse.
        self::assertFalse($orden->isSucia());
        self::assertFalse($orden->estaEmitida());
    }

    #[Test]
    public function emitirCongelaUnaLineaPorServicio(): void
    {
        $orden = $this->ordenCon($this->servicio('Traslado', '2026-09-01', 3, '120.00'));

        (new OperacionOrdenEmision())->emitir($orden);

        self::assertSame(EstadoOrdenServicioEnum::EMITIDA, $orden->getEstadoOs());
        self::assertCount(1, $orden->getItems());

        $item = $orden->getItems()->first();
        self::assertInstanceOf(OperacionOrdenServicioItem::class, $item);
        self::assertSame('Traslado', $item->getDescripcion());
        self::assertSame(3, $item->getCantidadPax());
        self::assertSame('120.00', $item->getImporte());
    }

    /** Recién emitida, el documento y La Biblia dicen lo mismo. */
    #[Test]
    public function reciénEmitidaNoEstaSucia(): void
    {
        $orden = $this->ordenCon($this->servicio('Traslado', '2026-09-01', 3, '120.00'));
        (new OperacionOrdenEmision())->emitir($orden);

        self::assertSame([], $orden->getDivergencias());
        self::assertFalse($orden->isSucia());
    }

    /**
     * El caso que motivó todo esto: la fecha se cambia en la COTIZACIÓN —para que el programa
     * del cliente quede al día— y el cambio baja a La Biblia. El documento ya no dice la verdad.
     */
    #[Test]
    public function cambiarLaFechaEnLaBibliaLaEnsucia(): void
    {
        $servicio = $this->servicio('Traslado', '2026-09-01', 3, '120.00');
        $orden = $this->ordenCon($servicio);
        (new OperacionOrdenEmision())->emitir($orden);

        $servicio->setFechaServicio(new DateTimeImmutable('2026-09-04'));

        self::assertTrue($orden->isSucia());
        self::assertStringContainsString('01/09/2026', $orden->getDivergencias()[0]);
        self::assertStringContainsString('04/09/2026', $orden->getDivergencias()[0]);
    }

    #[Test]
    public function cambiarLosPaxLaEnsucia(): void
    {
        $servicio = $this->servicio('Traslado', '2026-09-01', 3, '120.00');
        $orden = $this->ordenCon($servicio);
        (new OperacionOrdenEmision())->emitir($orden);

        $servicio->setCantidadPax(4);

        self::assertStringContainsString('se pidieron 3 pax y ahora son 4', $orden->getDivergencias()[0]);
    }

    /**
     * ⚠️ Negociar en el importe cotizado NO ensucia: mientras nadie pacte, lo vivo es lo
     * cotizado. Sin esto, toda orden nueva nacería sucia por un cero que sólo significa
     * «todavía sin pactar».
     */
    #[Test]
    public function elCostoNegociadoAceroNoEnsucia(): void
    {
        $servicio = $this->servicio('Traslado', '2026-09-01', 3, '120.00');
        $orden = $this->ordenCon($servicio);
        (new OperacionOrdenEmision())->emitir($orden);

        self::assertSame('0.00', $servicio->getCostoNegociado());
        self::assertSame([], $orden->getDivergencias());
    }

    #[Test]
    public function negociarOtroImporteSiLaEnsucia(): void
    {
        $servicio = $this->servicio('Traslado', '2026-09-01', 3, '120.00');
        $orden = $this->ordenCon($servicio);
        (new OperacionOrdenEmision())->emitir($orden);

        $servicio->setCostoNegociado('95.00');

        self::assertStringContainsString('el importe cambió', $orden->getDivergencias()[0]);
    }

    /** Anular: la Orden CONSERVA su contenido y la fila queda libre. Es la razón de todo esto. */
    #[Test]
    public function anularSueltaLaFilaYConservaElContenido(): void
    {
        $servicio = $this->servicio('Traslado', '2026-09-01', 3, '120.00');
        $orden = $this->ordenCon($servicio);
        $emision = new OperacionOrdenEmision();
        $emision->emitir($orden);

        $emision->anular($orden);

        self::assertSame(EstadoOrdenServicioEnum::CANCELADA, $orden->getEstadoOs());
        self::assertCount(1, $orden->getItems(), 'La orden anulada sigue diciendo lo que pidió.');
        self::assertNull($servicio->getOrdenServicio(), 'La fila queda libre para otra orden.');
        self::assertSame([], $orden->getDivergencias(), 'Una anulada ya no se persigue.');
    }

    /**
     * El flujo completo de reemisión, que es donde estaba el riesgo de perder trabajo.
     *
     * Anular a secas dejaría las filas sueltas en La Biblia: si el operador cierra la pestaña,
     * nadie recuerda al día siguiente qué siete iban juntas. Por eso la sucesora se crea en el
     * mismo paso — y **en borrador**, porque reemitir no es mandar otro papel a ciegas.
     */
    #[Test]
    public function reemitirDejaLaSucesoraListaSinPerderElHistorico(): void
    {
        $servicio = $this->servicio('Traslado', '2026-09-01', 3, '120.00');
        $vieja = $this->ordenCon($servicio);
        $emision = new OperacionOrdenEmision();
        $emision->emitir($vieja);

        // Lo que hace el endpoint: anular primero —así la fila queda libre y `validar()` la
        // acepta— y volver a enlazarla en la sucesora, todo en la misma transacción.
        $emision->anular($vieja);
        $emision->validar([$servicio]);

        $nueva = new OperacionOrdenServicio();
        $servicio->setOrdenServicio($nueva);
        $nueva->addOperacionServicio($servicio);
        $nueva->setReemplazaA($vieja);

        // La vieja conserva su contenido: el histórico no se toca.
        self::assertCount(1, $vieja->getItems());
        self::assertSame(EstadoOrdenServicioEnum::CANCELADA, $vieja->getEstadoOs());

        // La sucesora queda EN BORRADOR y sin congelar: es una vista viva hasta que se emita.
        self::assertFalse($nueva->estaEmitida());
        self::assertCount(0, $nueva->getItems());
        self::assertSame($vieja, $nueva->getReemplazaA());

        // Y la fila no quedó suelta en ningún momento.
        self::assertSame($nueva, $servicio->getOrdenServicio());
    }

    #[Test]
    public function laSucesoraApuntaALaAnulada(): void
    {
        $vieja = $this->ordenCon($this->servicio('Traslado', '2026-09-01', 3, '120.00'));
        $nueva = new OperacionOrdenServicio();

        (new OperacionOrdenEmision())->anular($vieja, $nueva);

        self::assertSame($vieja, $nueva->getReemplazaA());
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Un servicio COMPRABLE.
     *
     * La tarifa no es decorado: sin ella `isSoloReferencia()` da `true` y
     * `setOrdenServicio()` lo rechaza con un `DomainException` — la guarda que impide meter en
     * una Orden algo que nadie compra. Que el test tropiece con ella al construir el escenario
     * es, de hecho, la prueba de que sigue puesta.
     */
    private function servicio(string $descripcion, string $fecha, int $pax, string $costo): OperacionServicio
    {
        return (new OperacionServicio())
            ->setDescripcionServicio($descripcion)
            ->setFechaServicio(new DateTimeImmutable($fecha))
            ->setCantidadPax($pax)
            ->setCostoCotizado($costo)
            ->setCotizacionTarifa(new CotizacionCottarifa());
    }

    private function ordenCon(OperacionServicio ...$servicios): OperacionOrdenServicio
    {
        $orden = new OperacionOrdenServicio();
        foreach ($servicios as $servicio) {
            $orden->addOperacionServicio($servicio);
        }

        return $orden;
    }
}
