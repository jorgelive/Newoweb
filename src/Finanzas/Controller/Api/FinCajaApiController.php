<?php

declare(strict_types=1);

namespace App\Finanzas\Controller\Api;

use Symfony\Component\Uid\Uuid;
use App\Finanzas\Service\FinOrigenCobroRegistry;
use App\Finanzas\Dto\FinMovimientoFiltro;
use App\Finanzas\Entity\FinEnlacePago;
use App\Finanzas\Enum\FinEnlacePagoEstado;
use App\Finanzas\Repository\FinEnlacePagoRepository;
use App\Finanzas\Service\FinEnlacePagoSerializer;
use App\Finanzas\Service\FinEnlacePagoService;
use App\Finanzas\Service\FinMovimientoRegistry;
use App\Security\Roles;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Vista transversal de Finanzas: el módulo con sus dos pestañas.
 *
 * - **Cobros** (`/cobros`): enlaces de pago de todos los documentos, con su estado.
 *   Responde a "qué he emitido y en qué ha quedado".
 * - **Caja** (`/movimientos`): el dinero que ha entrado por cualquier medio —efectivo,
 *   Yape, transferencia, tarjeta—, reunido de todos los módulos por
 *   `FinMovimientoRegistry`. Responde a "cuánto entró y por dónde".
 *
 * Son dos preguntas distintas y por eso dos endpoints: un cobro emitido y no pagado sale
 * en la primera y no en la segunda, y un pago en efectivo al revés.
 *
 * Recordatorio del host: el de API cae en PUBLIC_ACCESS, así que lo único que protege
 * esto son los `#[IsGranted]`. No los quites.
 */
#[Route('/finanzas/caja', name: 'finanzas_caja_')]
final class FinCajaApiController extends AbstractController
{
    /**
     * Tope de filas por consulta.
     *
     * No hay paginación de verdad: en la caja el límite es POR MÓDULO (ver
     * `FinMovimientoRegistry::buscar()`), así que una paginación por página daría páginas
     * con huecos. Se prefiere un tope alto y filtros de fecha buenos, que es como se
     * consulta esto en la práctica; la respuesta avisa con `truncado` cuando se roza.
     */
    private const LIMITE = 500;

    public function __construct(
        private readonly FinEnlacePagoRepository $enlaces,
        private readonly FinEnlacePagoSerializer $serializador,
        private readonly FinEnlacePagoService $servicio,
        private readonly FinMovimientoRegistry $movimientos,
        // Para el detalle: quien sabe qué es una reserva o un expediente es su dominio.
        private readonly FinOrigenCobroRegistry $origenes,
    ) {}

    /**
     * Pestaña COBROS. `?estado=pendiente,pagado&desde=&hasta=&q=`
     */
    #[Route('/cobros', name: 'cobros', methods: ['GET'])]
    #[IsGranted(Roles::RESERVAS_SHOW, message: 'No tienes permiso para ver los cobros.')]
    public function cobros(Request $request): JsonResponse
    {
        // Que los caducados no figuren como pendientes antes de contarlos por estado.
        $this->servicio->marcarCaducados();

        $enlaces = $this->enlaces->buscar(
            estados: $this->estadosDesde($request->query->get('estado')),
            desde: $this->fechaDesde($request->query->get('desde')),
            hasta: $this->fechaDesde($request->query->get('hasta'), finDelDia: true),
            texto: $request->query->get('q'),
            limite: self::LIMITE,
        );

        return $this->json([
            'cobros' => array_map($this->serializador->aArray(...), $enlaces),
            'totales' => $this->totalesCobros($enlaces),
            'estados' => FinEnlacePagoEstado::opciones(),
            'truncado' => count($enlaces) >= self::LIMITE,
        ]);
    }

    /**
     * El detalle de UN cobro, con los datos de su documento de origen.
     *
     * ### Por qué un endpoint aparte y no más campos en el listado
     *
     * Resolver el origen es preguntarle a su dominio (`FinOrigenCobroRegistry::resolver()`),
     * y eso es al menos una consulta por fila. En un listado de hasta 500 serían 500
     * consultas para pintar datos que casi nunca se miran. Aquí se paga una sola vez, y sólo
     * cuando el operador abre la ficha.
     *
     * ### Qué devuelve según de dónde venga el cobro
     *
     * - **Manual**: `origen` es null y no falta nada — en un cobro suelto lo que se tecleó al
     *   crearlo (concepto, cliente, importe, referencia libre) ES todo lo que hay, y ya viaja
     *   en el propio enlace.
     * - **PMS / Cotizaciones**: `origen` trae lo que sabe ese módulo de su documento —la
     *   descripción de la estancia o el expediente, el localizador y **cuánto sigue
     *   debiendo**—, que es justo lo que no se puede deducir mirando el enlace.
     *
     * `origen` también sale null si el documento se borró o el módulo no lo reconoce: se
     * responde 200 con el cobro igualmente, porque el cobro existió y hay que poder verlo.
     */
    #[Route('/cobros/{id}', name: 'cobro_detalle', methods: ['GET'])]
    #[IsGranted(Roles::RESERVAS_SHOW, message: 'No tienes permiso para ver los cobros.')]
    public function cobroDetalle(string $id): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'Identificador de cobro inválido.'], 400);
        }

        $enlace = $this->enlaces->find(Uuid::fromString($id));

        if (!$enlace instanceof FinEnlacePago) {
            return $this->json(['error' => 'Ese cobro no existe.'], 404);
        }

        return $this->json([
            'cobro' => $this->serializador->aArray($enlace),
            'origen' => $this->origenDe($enlace),
        ]);
    }

    /**
     * Los datos del documento al que pertenece el cobro, preguntándoselo a su módulo.
     *
     * @return array<string, string|null>|null
     */
    private function origenDe(FinEnlacePago $enlace): ?array
    {
        $tipo = $enlace->getOrigenTipo();
        $origenId = $enlace->getOrigenId();

        if ($tipo === null || $origenId === null || !$this->origenes->soporta($tipo)) {
            return null;
        }

        $dto = $this->origenes->resolver($tipo, $origenId, $enlace->getMonedaCodigo());

        if ($dto === null) {
            return null;
        }

        return [
            'tipo' => $tipo->value,
            'tipoEtiqueta' => $tipo->etiqueta(),
            'descripcion' => $dto->descripcion,
            'referencia' => $dto->referencia,
            // Lo que el documento sigue debiendo HOY, no cuando se emitió el enlace: es el
            // dato por el que se abre esta ficha después de cobrar.
            'saldoPendiente' => $dto->saldoPendiente,
            'moneda' => $dto->moneda,
            'clienteNombre' => $dto->clienteNombre,
            'clienteEmail' => $dto->clienteEmail,
            'clienteTelefono' => $dto->clienteTelefono,
        ];
    }

    /**
     * Pestaña CAJA. `?medio=efectivo&desde=&hasta=&q=`
     */
    #[Route('/movimientos', name: 'movimientos', methods: ['GET'])]
    #[IsGranted(Roles::RESERVAS_SHOW, message: 'No tienes permiso para ver la caja.')]
    public function movimientos(Request $request): JsonResponse
    {
        $filtro = new FinMovimientoFiltro(
            desde: $this->fechaDesde($request->query->get('desde')),
            hasta: $this->fechaDesde($request->query->get('hasta'), finDelDia: true),
            medio: $request->query->get('medio'),
            texto: $request->query->get('q'),
            limite: self::LIMITE,
        );

        $movimientos = $this->movimientos->buscar($filtro);

        return $this->json([
            'movimientos' => array_map(static fn ($m): array => $m->aArray(), $movimientos),
            'totales' => $this->movimientos->totalesPorMoneda($movimientos),
            'medios' => $this->movimientos->mediosDisponibles(),
            'truncado' => count($movimientos) >= self::LIMITE,
        ]);
    }

    /**
     * Totales de la pestaña de cobros: por estado y moneda.
     *
     * Se suma `montoTotal` (lo que se cobra a la tarjeta) y no el neto, porque aquí la
     * pregunta es de tesorería —cuánto dinero mueve la pasarela—, no cuánto abona la
     * reserva. En la pestaña de caja es al revés y se suma el neto: el recargo se lo
     * queda la pasarela y nunca llega a nuestra cuenta.
     *
     * @param FinEnlacePago[] $enlaces
     * @return array<int, array{estado: string, etiqueta: string, moneda: string, total: string, registros: int}>
     */
    private function totalesCobros(array $enlaces): array
    {
        $acumulado = [];

        foreach ($enlaces as $enlace) {
            $clave = $enlace->getEstado()->value . '|' . ($enlace->getMonedaCodigo() ?? '');
            $acumulado[$clave] ??= ['estado' => $enlace->getEstado(), 'moneda' => $enlace->getMonedaCodigo() ?? '', 'total' => 0.0, 'registros' => 0];
            $acumulado[$clave]['total'] += (float) $enlace->getMontoTotal();
            $acumulado[$clave]['registros']++;
        }

        ksort($acumulado);

        $totales = [];
        foreach ($acumulado as $datos) {
            $totales[] = [
                'estado' => $datos['estado']->value,
                'etiqueta' => $datos['estado']->etiqueta(),
                'moneda' => $datos['moneda'],
                'total' => number_format($datos['total'], 2, '.', ''),
                'registros' => $datos['registros'],
            ];
        }

        return $totales;
    }

    /** @return string[] */
    private function estadosDesde(mixed $valor): array
    {
        if (!is_string($valor) || trim($valor) === '') {
            return [];
        }

        $validos = array_column(FinEnlacePagoEstado::cases(), 'value');

        return array_values(array_intersect(explode(',', $valor), $validos));
    }

    /**
     * `Y-m-d` → objeto. `$finDelDia` empuja al último segundo: sin eso, un `hasta=hoy`
     * dejaba fuera todo lo de hoy, porque `2026-08-08 00:00` es anterior a cualquier
     * registro del día. Es el fallo clásico de los filtros por rango.
     */
    private function fechaDesde(mixed $valor, bool $finDelDia = false): ?DateTimeImmutable
    {
        if (!is_string($valor) || trim($valor) === '') {
            return null;
        }

        $fecha = DateTimeImmutable::createFromFormat('Y-m-d', trim($valor));

        if ($fecha === false) {
            return null;
        }

        return $finDelDia ? $fecha->setTime(23, 59, 59) : $fecha->setTime(0, 0);
    }
}
