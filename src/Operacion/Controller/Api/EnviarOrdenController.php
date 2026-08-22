<?php

declare(strict_types=1);

namespace App\Operacion\Controller\Api;

use App\Operacion\Entity\OperacionOrdenServicio;
use App\Operacion\Service\OperacionOrdenEnvio;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Previsualizar y mandar una Orden de Servicio al proveedor.
 *
 * ```
 * GET  /platform/ops/orden-servicios/{id}/documento   → qué se mandaría y por qué canales
 * POST /platform/ops/orden-servicios/{id}/enviar      → {"canal": "email"}
 * ```
 *
 * ── Por qué se previsualiza ─────────────────────────────────────────────────
 * Porque es **irreversible**: un correo mandado no se retira. El documento se compone de los
 * ítems congelados y va a la dirección que resuelve la identidad del proveedor, así que hay dos
 * cosas que sólo se ven mirando —que las líneas son las que se pactaron, y que va a donde debe—
 * y las dos se comprueban en dos segundos antes de pulsar.
 *
 * ── Y por qué es un endpoint aparte de emitir ───────────────────────────────
 * Emitir congela el contenido; enviar se lo pone delante a alguien de fuera. Son dos decisiones
 * y fallan distinto. Separadas, **reenviar no necesita código propio**: es este mismo endpoint
 * otra vez, que es lo normal cuando el proveedor perdió el correo o cambió de contacto.
 */
#[AsController]
#[Route('/platform/ops/orden-servicios/{id}', name: 'ops_orden_')]
final class EnviarOrdenController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OperacionOrdenEnvio $envio,
    ) {
    }

    #[Route('/documento', name: 'documento', methods: ['GET'])]
    #[IsGranted(Roles::OPERACIONES_SHOW, message: 'Acceso denegado a las órdenes de servicio.')]
    public function documento(string $id): JsonResponse
    {
        $orden = $this->orden($id);

        if ($orden === null) {
            return $this->json(['error' => 'Esa orden ya no existe.'], Response::HTTP_NOT_FOUND);
        }

        try {
            return $this->json($this->envio->previsualizar($orden));
        } catch (DomainException $e) {
            // Sin proveedor del catálogo no hay a quién escribir: se dice al previsualizar, que
            // es cuando todavía se puede arreglar, y no al pulsar «Enviar».
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
    }

    #[Route('/enviar', name: 'enviar', methods: ['POST'])]
    #[IsGranted(Roles::OPERACIONES_WRITE, message: 'No tienes permiso para enviar órdenes de servicio.')]
    public function enviar(string $id, Request $request): JsonResponse
    {
        $orden = $this->orden($id);

        if ($orden === null) {
            return $this->json(['error' => 'Esa orden ya no existe.'], Response::HTTP_NOT_FOUND);
        }

        /** @var array<string, mixed> $cuerpo */
        $cuerpo = json_decode($request->getContent() ?: '{}', true) ?: [];
        $canal = trim((string) ($cuerpo['canal'] ?? ''));

        if ($canal === '') {
            return $this->json(['error' => 'Falta por qué canal enviarla.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $anotacion = $this->envio->enviar($orden, $canal);
        } catch (DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->json([
            'enviado' => true,
            'canal' => $canal,
            'mensajeId' => (string) $anotacion->getId(),
        ]);
    }

    private function orden(string $id): ?OperacionOrdenServicio
    {
        return Uuid::isValid($id)
            ? $this->em->getRepository(OperacionOrdenServicio::class)->find(Uuid::fromString($id))
            : null;
    }
}
