<?php

declare(strict_types=1);

namespace App\Api\Controller\ImageUpload;

use App\Travel\Entity\ProveedorServicio;
use App\Travel\Entity\ProveedorServicioImagen;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Controlador API encargado de recibir y procesar la carga masiva de imágenes
 * para la galería de la entidad ProveedorServicio (ej. Habitaciones, Tours, etc.).
 */
#[Route('/api/travel/proveedor-servicio-imagen')]
class ProveedorServicioImagenUploadController extends AbstractController
{
    /**
     * Procesa la subida de una imagen individual asociada a un servicio de proveedor específico.
     *
     * @param Request $request La petición HTTP entrante enviada por el componente Stimulus.
     * @param EntityManagerInterface $em El gestor de entidades de Doctrine.
     * @return JsonResponse Respuesta estructurada requerida por el widget de subida del panel.
     */
    #[Route('/upload', name: 'api_travel_proveedor_servicio_imagen_upload', methods: ['POST'])]
    public function upload(Request $request, EntityManagerInterface $em): JsonResponse
    {
        // 1. Obtener datos del Request
        $uploadedFile = $request->files->get('file');
        $proveedorServicioId = $request->request->get('proveedor_servicio_id');

        // 2. Validaciones básicas
        if (!$uploadedFile) {
            return $this->json(['error' => 'No se ha enviado ningún archivo'], Response::HTTP_BAD_REQUEST);
        }

        if (!$proveedorServicioId) {
            return $this->json(['error' => 'Falta el ID del Servicio destino'], Response::HTTP_BAD_REQUEST);
        }

        // 3. Buscar la entidad Padre (ProveedorServicio)
        $proveedorServicio = $em->getRepository(ProveedorServicio::class)->find($proveedorServicioId);

        if (!$proveedorServicio) {
            return $this->json(['error' => 'El Servicio especificado no existe'], Response::HTTP_NOT_FOUND);
        }

        // 4. Crear y persistir la entidad Hija (ProveedorServicioImagen)
        try {
            $imagen = new ProveedorServicioImagen();
            $imagen->setProveedorServicio($proveedorServicio);

            // Asignar el archivo para que VichUploader y LiipImagine lo procesen
            $imagen->setImageFile($uploadedFile);

            // Por defecto, las cargas masivas no marcan la imagen como portada
            $imagen->setIsPortada(false);

            // Calcular orden al final (max + 1) dentro del mismo servicio
            $proveedorServicioUuid = Uuid::fromString((string) $proveedorServicioId);

            $maxOrden = (int) $em->createQueryBuilder()
                ->select('COALESCE(MAX(i.orden), -1)')
                ->from(ProveedorServicioImagen::class, 'i')
                ->andWhere('IDENTITY(i.proveedorServicio) = :proveedorServicioId')
                ->setParameter('proveedorServicioId', $proveedorServicioUuid, 'uuid')
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