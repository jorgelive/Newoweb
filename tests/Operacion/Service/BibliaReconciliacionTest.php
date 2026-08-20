<?php

declare(strict_types=1);

namespace App\Tests\Operacion\Service;

use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionCotcomponente;
use App\Cotizacion\Entity\CotizacionCotservicio;
use App\Operacion\ApiPlatform\Dto\CambioPropuesto;
use App\Operacion\Entity\OperacionServicio;
use App\Operacion\Service\BibliaReconciliacionService;
use App\Operacion\Service\BibliaSnapshotService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * El sincronizador: qué propone al comparar la cotización con La Biblia.
 *
 * El servicio sólo toca la base para UNA consulta —traer las filas de los cotservicios—, así
 * que simulándola se prueba la lógica de comparación entera sin contenedor ni datos.
 *
 * Es la pieza que decide si un cambio se aplica solo o si para y pregunta, y hasta ahora no
 * tenía ninguna prueba.
 */
final class BibliaReconciliacionTest extends TestCase
{
    /** Un componente que todavía no tiene fila: hay que crearla. */
    #[Test]
    public function unComponenteSinFilaSaleComoCrear(): void
    {
        $cotizacion = $this->cotizacionCon($this->componente('2026-09-01'));

        $plan = $this->servicio()->planificar($cotizacion);

        self::assertCount(1, $plan->cambios);
        self::assertSame(CambioPropuesto::TIPO_CREAR, $plan->cambios[0]->tipo);
        self::assertSame(0, $plan->sinCambios);
    }

    /** Con la fila al día no se propone nada: es el caso normal y no debe generar ruido. */
    #[Test]
    public function unaFilaAlDiaNoProponeNada(): void
    {
        $componente = $this->componente('2026-09-01');
        $cotizacion = $this->cotizacionCon($componente);
        $fila = $this->filaDe($componente, $cotizacion);

        $plan = $this->servicio($fila)->planificar($cotizacion);

        self::assertSame([], $plan->cambios);
        self::assertSame(1, $plan->sinCambios);
    }

    /**
     * El caso que importa: la fecha se cambia en la COTIZACIÓN —para que el programa del
     * cliente quede al día— y el sincronizador la baja a La Biblia.
     */
    #[Test]
    public function cambiarLaFechaEnLaCotizacionSePropone(): void
    {
        $componente = $this->componente('2026-09-01');
        $cotizacion = $this->cotizacionCon($componente);
        $fila = $this->filaDe($componente, $cotizacion);

        $componente->setFechaHoraInicio(new DateTimeImmutable('2026-09-04 08:00'));

        $plan = $this->servicio($fila)->planificar($cotizacion);

        self::assertCount(1, $plan->cambios);
        self::assertSame(CambioPropuesto::TIPO_ACTUALIZAR, $plan->cambios[0]->tipo);

        $campos = array_column($plan->cambios[0]->campos, null, 'campo');
        self::assertArrayHasKey('fechaServicio', $campos);
        self::assertSame('2026-09-04', $campos['fechaServicio']->valorPropuesto);
    }

    /**
     * ⚠️ Sin foto de referencia todo es conflicto, y es lo conservador: si no se sabe quién
     * movió el dato, no se decide en automático.
     */
    #[Test]
    public function sinSnapshotOrigenElCambioSaleEnConflicto(): void
    {
        $componente = $this->componente('2026-09-01');
        $cotizacion = $this->cotizacionCon($componente);
        $fila = $this->filaDe($componente, $cotizacion)->setSnapshotOrigen([]);

        $componente->setFechaHoraInicio(new DateTimeImmutable('2026-09-04 08:00'));

        $plan = $this->servicio($fila)->planificar($cotizacion);

        self::assertTrue($plan->cambios[0]->campos[0]->enConflicto);
    }

    /** Con foto, y el operador sin tocar nada, la cotización manda y se aplica sin preguntar. */
    #[Test]
    public function conFotoYSinEdicionDelOperadorNoHayConflicto(): void
    {
        $componente = $this->componente('2026-09-01');
        $cotizacion = $this->cotizacionCon($componente);
        $fila = $this->filaDe($componente, $cotizacion);

        $componente->setFechaHoraInicio(new DateTimeImmutable('2026-09-04 08:00'));

        $plan = $this->servicio($fila)->planificar($cotizacion);

        self::assertFalse($plan->cambios[0]->campos[0]->enConflicto);
    }

    /** Una fila cuyo componente desapareció de la cotización queda huérfana. */
    #[Test]
    public function unaFilaSinComponenteQuedaHuerfana(): void
    {
        $cotizacion = $this->cotizacionCon($this->componente('2026-09-01'));

        // Un componente de OTRA cotización: su fila ya no tiene de dónde colgar.
        $otra = $this->cotizacionCon($fuera = $this->componente('2026-09-02'));
        $huerfana = $this->filaDe($fuera, $otra);

        $plan = $this->servicio($huerfana)->planificar($cotizacion);

        $tipos = array_column($plan->cambios, 'tipo');
        self::assertContains(CambioPropuesto::TIPO_HUERFANO, $tipos);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * El servicio con la única consulta simulada.
     *
     * `createStub` y no `createMock`: aquí no se verifica que se llame a nada, sólo se
     * responde. Con `failOnNotice` activo, un mock sin expectativas tumba la suite — y con
     * razón, porque anuncia una comprobación que no existe.
     */
    private function servicio(OperacionServicio ...$filas): BibliaReconciliacionService
    {
        $repo = self::createStub(EntityRepository::class);
        $repo->method('findBy')->willReturn($filas);

        $em = self::createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        return new BibliaReconciliacionService($em, new BibliaSnapshotService($em));
    }

    /** Sin tarifas: entra como fila de referencia, que es el componente más simple posible. */
    private function componente(string $fecha): CotizacionCotcomponente
    {
        return (new CotizacionCotcomponente())
            ->setFechaHoraInicio(new DateTimeImmutable($fecha . ' 08:00'));
    }

    private function cotizacionCon(CotizacionCotcomponente ...$componentes): Cotizacion
    {
        $cotservicio = new CotizacionCotservicio();
        $cotservicio->setFechaInicioAbsoluta(new DateTimeImmutable('2026-09-01'));

        foreach ($componentes as $componente) {
            $cotservicio->addCotcomponente($componente);
            $componente->setCotservicio($cotservicio);
        }

        $cotizacion = new Cotizacion();
        $cotizacion->addCotservicio($cotservicio);

        return $cotizacion;
    }

    /** La fila que el snapshot habría generado para ese componente, con su foto de referencia. */
    private function filaDe(CotizacionCotcomponente $componente, Cotizacion $cotizacion): OperacionServicio
    {
        $snapshot = new BibliaSnapshotService(self::createStub(EntityManagerInterface::class));
        $cotservicio = $componente->getCotservicio();
        self::assertNotNull($cotservicio);

        $valores = $snapshot->calcularValores($componente, $cotservicio, $cotizacion->getNumPax());
        self::assertIsArray($valores);

        $fila = new OperacionServicio();
        $fila->setCotizacionComponente($componente);
        $snapshot->aplicarValores($fila, $valores, $componente);
        $fila->setSnapshotOrigen($valores);

        return $fila;
    }
}
