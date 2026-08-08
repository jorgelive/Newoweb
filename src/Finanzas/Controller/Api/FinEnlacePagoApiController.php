<?php

declare(strict_types=1);

namespace App\Finanzas\Controller\Api;

use App\Finanzas\Entity\FinEnlacePago;
use App\Finanzas\Enum\FinOrigenCobro;
use App\Finanzas\Repository\FinEnlacePagoRepository;
use App\Finanzas\Service\FinEnlacePagoService;
use App\Finanzas\Service\FinOrigenCobroRegistry;
use App\Security\Roles;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use ValueError;

/**
 * API interna de enlaces de pago, consumida por la SPA `util`.
 *
 * Va por controlador y no por API Platform porque crear un enlace no es "POST de un
 * recurso": lee el saldo de otro módulo, habla con la pasarela y congela importes. Un
 * `Post` de API Platform con un processor escondería ese trabajo detrás de una fachada CRUD
 * que no describe lo que pasa.
 *
 * Ojo: el host de la API cae en PUBLIC_ACCESS en `security.yaml` — la protección de estos
 * endpoints son los `#[IsGranted]` de aquí, igual que en el controlador del asistente. No
 * los quites.
 */
#[Route('/finanzas/enlaces-pago', name: 'finanzas_enlace_pago_')]
final class FinEnlacePagoApiController extends AbstractController
{
    public function __construct(
        private readonly FinEnlacePagoService $servicio,
        private readonly FinEnlacePagoRepository $repository,
        private readonly FinOrigenCobroRegistry $registry,
    ) {}

    /**
     * Enlaces de un documento. `?origenTipo=pms_reserva&origenId=<uuid>`.
     */
    #[Route('', name: 'listar', methods: ['GET'])]
    #[IsGranted(Roles::RESERVAS_SHOW, message: 'No tienes permiso para ver los cobros.')]
    public function listar(Request $request): JsonResponse
    {
        $tipo = $this->tipoDesde($request->query->get('origenTipo'));
        $origenId = $this->uuidDesde($request->query->get('origenId'));

        if ($tipo === null || $origenId === null) {
            return $this->json(['error' => 'Faltan origenTipo u origenId.'], 400);
        }

        // Antes de listar, que los caducados dejen de figurar como pendientes.
        $this->servicio->marcarCaducados();

        $enlaces = $this->repository->porOrigen($tipo, $origenId);

        return $this->json(['enlaces' => array_map($this->serializar(...), $enlaces)]);
    }

    /**
     * Emite un enlace.
     *
     * Body: `{origenTipo, origenId, monto?, conRecargo?, vigenciaDias?, concepto?}`.
     * `monto` es el NETO; si se omite se cobra el saldo pendiente completo.
     */
    #[Route('', name: 'crear', methods: ['POST'])]
    #[IsGranted(Roles::RESERVAS_WRITE, message: 'No tienes permiso para emitir cobros.')]
    public function crear(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = json_decode((string) $request->getContent(), true) ?: [];

        $tipo = $this->tipoDesde($datos['origenTipo'] ?? null);
        $origenId = $this->uuidDesde($datos['origenId'] ?? null);

        if ($tipo === null || $origenId === null) {
            return $this->json(['error' => 'Faltan origenTipo u origenId.'], 400);
        }

        if (!$this->registry->soporta($tipo)) {
            return $this->json(['error' => 'Ese origen todavía no admite cobros.'], 422);
        }

        try {
            $enlace = $this->servicio->crear(
                origenTipo: $tipo,
                origenId: $origenId,
                montoNeto: isset($datos['monto']) ? (string) $datos['monto'] : null,
                conRecargo: (bool) ($datos['conRecargo'] ?? true),
                vigenciaDias: isset($datos['vigenciaDias']) ? (int) $datos['vigenciaDias'] : null,
                concepto: isset($datos['concepto']) ? (string) $datos['concepto'] : null,
                creadoPor: $this->getUser() instanceof \App\Entity\User ? $this->getUser() : null,
            );
        } catch (DomainException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }

        return $this->json(['enlace' => $this->serializar($enlace)], 201);
    }

    #[Route('/{id}/anular', name: 'anular', methods: ['POST'])]
    #[IsGranted(Roles::RESERVAS_WRITE, message: 'No tienes permiso para anular cobros.')]
    public function anular(string $id): JsonResponse
    {
        $enlace = $this->repository->find($id);

        if (!$enlace instanceof FinEnlacePago) {
            return $this->json(['error' => 'Enlace no encontrado.'], 404);
        }

        try {
            $this->servicio->anular($enlace);
        } catch (DomainException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }

        return $this->json(['enlace' => $this->serializar($enlace)]);
    }

    /** Orígenes que hoy tienen resolver. La SPA pinta esto, no el enum entero. */
    #[Route('/origenes', name: 'origenes', methods: ['GET'])]
    #[IsGranted(Roles::RESERVAS_SHOW)]
    public function origenes(): JsonResponse
    {
        return $this->json($this->registry->opcionesDisponibles());
    }

    /**
     * Forma del enlace para la SPA.
     *
     * A mano y no con el serializer de Symfony porque la URL pública no es un campo de la
     * entidad —se compone con el host de `pax`— y es justo el dato que va a copiar el
     * operador. Espejo en TS: `util/src/types/finEnlacePagoModel.ts`.
     *
     * @return array<string, mixed>
     */
    private function serializar(FinEnlacePago $enlace): array
    {
        return [
            'id' => (string) $enlace->getId(),
            'url' => $this->servicio->urlPublica($enlace),
            'estado' => $enlace->getEstado()->value,
            'estadoEtiqueta' => $enlace->getEstado()->etiqueta(),
            'vigente' => $enlace->estaVigente(),
            'moneda' => $enlace->getMonedaCodigo(),
            'monedaSimbolo' => $enlace->getMonedaSimbolo(),
            'montoNeto' => $enlace->getMontoNeto(),
            'montoRecargo' => $enlace->getMontoRecargo(),
            'montoTotal' => $enlace->getMontoTotal(),
            'recargoPorcentaje' => $enlace->getRecargoPorcentaje(),
            'concepto' => $enlace->getConcepto(),
            'ordenId' => $enlace->getOrdenId(),
            'expiraEn' => $enlace->getExpiraEn()?->format(DATE_ATOM),
            'pagadoEn' => $enlace->getPagadoEn()?->format(DATE_ATOM),
            'medioDetalle' => $enlace->getMedioDetalle(),
            'autorizacionCodigo' => $enlace->getAutorizacionCodigo(),
            'creadoPorNombre' => $enlace->getCreadoPorNombre(),
            'createdAt' => $enlace->getCreatedAt()?->format(DATE_ATOM),
        ];
    }

    private function tipoDesde(mixed $valor): ?FinOrigenCobro
    {
        if (!is_string($valor) || $valor === '') {
            return null;
        }

        try {
            return FinOrigenCobro::from($valor);
        } catch (ValueError) {
            return null;
        }
    }

    private function uuidDesde(mixed $valor): ?Uuid
    {
        if (!is_string($valor) || !Uuid::isValid($valor)) {
            return null;
        }

        return Uuid::fromString($valor);
    }
}
