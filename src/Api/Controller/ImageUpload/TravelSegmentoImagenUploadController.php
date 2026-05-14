<?php

declare(strict_types=1);

namespace App\Api\Controller\ImageUpload;

use App\Travel\Entity\TravelSegmento;
use App\Travel\Entity\TravelSegmentoImagen;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Controlador API encargado de recibir y procesar la carga masiva de imágenes
 * para la entidad TravelSegmento.
 */
#[Route('/api/travel/segmento-imagen')]
class TravelSegmentoImagenUploadController extends AbstractController
{
    /**
     * Procesa la subida de una imagen individual asociada a un segmento específico.
     *
     * @param Request $request
     * @param EntityManagerInterface $em
     * @return JsonResponse
     */
    #[Route('/upload', name: 'api_travel_segmento_imagen_upload', methods: ['POST'])]
    public function upload(Request $request, EntityManagerInterface $em): JsonResponse
    {
        // 1. Obtener datos del Request
        $uploadedFile = $request->files->get('file');
        $segmentoId = $request->request->get('segmento_id');

        // 2. Validaciones básicas
        if (!$uploadedFile) {
            return $this->json(['error' => 'No se ha enviado ningún archivo'], 400);
        }

        if (!$segmentoId) {
            return $this->json(['error' => 'Falta el ID del Segmento destino'], 400);
        }

        // 3. Buscar la entidad Padre (TravelSegmento)
        $segmento = $em->getRepository(TravelSegmento::class)->find($segmentoId);

        if (!$segmento) {
            return $this->json(['error' => 'El Segmento especificado no existe'], 404);
        }

        // 4. Crear y persistir la entidad Hija (TravelSegmentoImagen)
        try {
            $imagen = new TravelSegmentoImagen();
            $imagen->setSegmento($segmento);

            // Asignar el archivo para que VichUploader lo procese
            $imagen->setImageFile($uploadedFile);

            // Por defecto, las cargas masivas no son portada
            $imagen->setIsPortada(false);

            // Calcular orden al final (max + 1) dentro del mismo segmento
            $segmentoUuid = Uuid::fromString((string) $segmentoId);

            $maxOrden = (int) $em->createQueryBuilder()
                ->select('COALESCE(MAX(i.orden), -1)')
                ->from(TravelSegmentoImagen::class, 'i')
                ->andWhere('IDENTITY(i.segmento) = :segmentoId')
                ->setParameter('segmentoId', $segmentoUuid, 'uuid')
                ->getQuery()
                ->getSingleScalarResult();

            $imagen->setOrden($maxOrden + 1);

            $em->persist($imagen);
            $em->flush();

            return $this->json([
                'status' => 'ok',
                'id' => $imagen->getId(),
                'imageUrl' => $imagen->getImageName(), // Devuelve el nombre generado
                'message' => 'Imagen subida correctamente'
            ]);

        } catch (\Exception $e) {
            return $this->json(['error' => 'Error al guardar la imagen: ' . $e->getMessage()], 500);
        }
    }
}