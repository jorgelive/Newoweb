<?php

declare(strict_types=1);

namespace App\Finanzas\Controller\Api;

use App\Finanzas\Entity\FinEnlacePago;
use App\Finanzas\Enum\FinOrigenCobro;
use App\Finanzas\Enum\FinPasarela;
use App\Finanzas\Repository\FinEnlacePagoRepository;
use App\Finanzas\Service\FinPasarelaRegistry;
use App\Finanzas\Service\FinEnlacePagoSerializer;
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
        private readonly FinEnlacePagoSerializer $serializador,
        private readonly FinPasarelaRegistry $pasarelas,
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
                // Null = la del registry. El operador sólo elige si hay más de una con
                // credenciales; el selector de la SPA se puebla desde `/pasarelas`.
                pasarela: isset($datos['pasarela']) ? FinPasarela::tryFrom((string) $datos['pasarela']) : null,
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

    /**
     * Respuesta CRUDA de la pasarela, para auditoría.
     *
     * Endpoint aparte y no un campo del listado a propósito: son varios KB por fila y se
     * consultan una vez al año, cuando alguien reclama. Meterlos en la lista inflaría cada
     * consulta de 500 filas para un dato que casi nunca se mira.
     *
     * Se devuelve **tal cual llegó**, sin mapear a ninguna estructura nuestra. Es
     * deliberado: cada pasarela responde lo que quiere —un `charge` de Culqi y un
     * `kr-answer` de Lyra no comparten un solo campo— y modelarlo ataría Finanzas a la
     * forma de una de ellas. El que audita quiere ver lo que dijo la pasarela, no nuestra
     * interpretación de lo que dijo.
     */
    #[Route('/{id}/respuesta', name: 'respuesta', methods: ['GET'])]
    #[IsGranted(Roles::RESERVAS_SHOW, message: 'No tienes permiso para ver los cobros.')]
    public function respuesta(string $id): JsonResponse
    {
        $enlace = $this->repository->find($id);

        if (!$enlace instanceof FinEnlacePago) {
            return $this->json(['error' => 'Enlace no encontrado.'], 404);
        }

        return $this->json([
            'pasarela' => $enlace->getPasarela()->value,
            'pasarelaEtiqueta' => $enlace->getPasarela()->etiqueta(),
            'respuesta' => $enlace->getRespuestaPasarela(),
        ]);
    }

    /** Orígenes que hoy tienen resolver. La SPA pinta esto, no el enum entero. */
    #[Route('/origenes', name: 'origenes', methods: ['GET'])]
    #[IsGranted(Roles::RESERVAS_SHOW)]
    public function origenes(): JsonResponse
    {
        return $this->json($this->registry->opcionesDisponibles());
    }

    /**
     * Pasarelas CON credenciales, para el selector del formulario.
     *
     * Sólo las configuradas: ofrecer una pasarela a medio configurar es prometer un cobro
     * que va a fallar al pulsar el botón. Si sólo hay una, la SPA ni pinta el selector.
     */
    #[Route('/pasarelas', name: 'pasarelas', methods: ['GET'])]
    #[IsGranted(Roles::RESERVAS_SHOW)]
    public function pasarelas(): JsonResponse
    {
        return $this->json($this->pasarelas->opcionesDisponibles());
    }

    /** @return array<string, mixed> */
    private function serializar(FinEnlacePago $enlace): array
    {
        return $this->serializador->aArray($enlace);
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
