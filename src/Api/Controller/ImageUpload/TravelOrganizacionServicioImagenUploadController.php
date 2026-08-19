<?php

declare(strict_types=1);

namespace App\Api\Controller\ImageUpload;

use App\Travel\Entity\TravelOrganizacionServicio;
use App\Travel\Entity\TravelOrganizacionServicioImagen;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Controlador API encargado de recibir y procesar la carga masiva de imágenes
 * para la galería de la entidad TravelOrganizacionServicio (ej. Habitaciones, Tours, etc.).
 */
#[Route('/api/travel/organizacion-servicio-imagen')]
class TravelOrganizacionServicioImagenUploadController extends AbstractController
{
    /**
     * Procesa la subida de una imagen individual asociada a un servicio de proveedor específico.
     *
     * @param Request $request La petición HTTP entrante enviada por el componente Stimulus.
     * @param EntityManagerInterface $em El gestor de entidades de Doctrine.
     * @return JsonResponse Respuesta estructurada requerida por el widget de subida del panel.
     */
    #[Route('/upload', name: 'api_travel_organizacion_servicio_imagen_upload', methods: ['POST'])]
    public function upload(Request $request, EntityManagerInterface $em): JsonResponse
    {
        // 1. Obtener datos del Request
        $uploadedFile = $request->files->get('file');
        $organizacionServicioId = $request->request->get('proveedor_servicio_id');

        // 2. Validaciones básicas
        if (!$uploadedFile) {
            return $this->json(['error' => 'No se ha enviado ningún archivo'], Response::HTTP_BAD_REQUEST);
        }

        if (!$organizacionServicioId) {
            return $this->json(['error' => 'Falta el ID del Servicio destino'], Response::HTTP_BAD_REQUEST);
        }

        // 3. Buscar la entidad Padre (TravelOrganizacionServicio)
        $organizacionServicio = $em->getRepository(TravelOrganizacionServicio::class)->find($organizacionServicioId);

        if (!$organizacionServicio) {
            return $this->json(['error' => 'El Servicio especificado no existe'], Response::HTTP_NOT_FOUND);
        }

        // 4. Crear y persistir la entidad Hija (TravelOrganizacionServicioImagen)
        try {
            $imagen = new TravelOrganizacionServicioImagen();
            $imagen->setOrganizacionServicio($organizacionServicio);

            // Asignar el archivo para que VichUploader y LiipImagine lo procesen
            $imagen->setImageFile($uploadedFile);

            // Por defecto, las cargas masivas no marcan la imagen como portada
            $imagen->setIsPortada(false);

            // Calcular orden al final (max + 1) dentro del mismo servicio
            $proveedorServicioUuid = Uuid::fromString((string) $organizacionServicioId);

            $maxOrden = (int) $em->createQueryBuilder()
                ->select('COALESCE(MAX(i.orden), -1)')
                ->from(TravelOrganizacionServicioImagen::class, 'i')
                ->andWhere('IDENTITY(i.organizacionServicio) = :organizacionServicioId')
                ->setParameter('organizacionServicioId', $proveedorServicioUuid, 'uuid')
                ->getQuery()
                ->getSingleScalarResult();

            $imagen->setOrden($maxOrden + 1);

            $em->persist($imagen);
            $em->flush();

            return $this->json([
                'status' => 'ok',
                'id' => $imagen->getId(),
                'imageUrl' => $imagen->getImageName(),
                'message' => 'Imagen subida correctamente'
            ], Response::HTTP_CREATED);

        } catch (\Exception $e) {
            return $this->json(['error' => 'Error al guardar la imagen: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}