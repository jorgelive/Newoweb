<?php

namespace App\Api\Controller\Pax;

use App\Api\Controller\Pax\Trait\GuiaHelperResponseTrait;
use App\Pms\Entity\PmsUnidad;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;

#[AsController]
class GuiaHelperPublicController
{
    use GuiaHelperResponseTrait;

    public function __construct(private EntityManagerInterface $em) {}

    // 🔥 Endpoint para QRs o Demo (Usa ID/UUID de Unidad)
    #[Route('/pax/public/guiahelper/{id}', name: 'pax_public_guia_helper', methods: ['GET'])]
    public function __invoke(string $id): JsonResponse
    {
        // Buscamos la unidad (asumiendo que 'id' puede ser UUID o ID interno según tu config)
        $unidad = $this->em->getRepository(PmsUnidad::class)->find($id);

        if (!$unidad) {
            return new JsonResponse(['error' => 'Unidad no encontrada'], 404);
        }

        // Llamamos al trait pasando NULL como evento -> Modo Demo
        return $this->buildResponse($unidad, null);
    }
}