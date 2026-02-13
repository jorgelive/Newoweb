<?php

namespace App\Api\Controller\Pax;

use App\Api\Controller\Pax\Trait\GuiaHelperResponseTrait;
use App\Pms\Entity\PmsEventoCalendario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;

#[AsController]
class GuiaHelperClientController
{
    use GuiaHelperResponseTrait;

    public function __construct(private EntityManagerInterface $em) {}

    // 🔒 Endpoint para Huéspedes (Usa ID/UUID de Evento)
    #[Route('/pax/client/guiahelper/{id}', name: 'pax_client guia_helper', methods: ['GET'])]
    public function __invoke(string $id): JsonResponse
    {
        // Buscamos el evento de calendario
        $evento = $this->em->getRepository(PmsEventoCalendario::class)->find($id);

        if (!$evento) {
            return new JsonResponse(['error' => 'Reserva no encontrada o evento inválido'], 404);
        }

        // Llamamos al trait pasando el evento -> Modo Guest (si las fechas coinciden)
        return $this->buildResponse($evento->getPmsUnidad(), $evento);
    }
}