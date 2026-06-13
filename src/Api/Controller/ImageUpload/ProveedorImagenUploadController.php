<?php

declare(strict_types=1);

namespace App\Api\Controller\ImageUpload;

use App\Travel\Entity\Proveedor;
use App\Travel\Entity\ProveedorImagen;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Controlador API encargado de recibir y procesar la carga masiva de imágenes
 * para la galería de la entidad Proveedor.
 */
#[Route('/api/travel/proveedor-imagen')]
class ProveedorImagenUploadController extends AbstractController
{
    /**
     * Procesa la subida de una imagen individual asociada a un proveedor específico.
     *
     * @param Request $request
     * @param EntityManagerInterface $em
     * @return JsonResponse
     */
    #[Route('/upload', name: 'api_travel_proveedor_imagen_upload', methods: ['POST'])]
    public function upload(Request $request, EntityManagerInterface $em): JsonResponse
    {
        // 1. Obtener datos del Request
        $uploadedFile = $request->files->get('file');
        $proveedorId = $request->request->get('proveedor_id');

        // 2. Validaciones básicas
        if (!$uploadedFile) {
            return $this->json(['error' => 'No se ha enviado ningún archivo'], Response::HTTP_BAD_REQUEST);
        }

        if (!$proveedorId) {
            return $this->json(['error' => 'Falta el ID del Proveedor destino'], Response::HTTP_BAD_REQUEST);
        }

        // 3. Buscar la entidad Padre (Proveedor)
        $proveedor = $em->getRepository(Proveedor::class)->find($proveedorId);

        if (!$proveedor) {
            return $this->json(['error' => 'El Proveedor especificado no existe'], Response::HTTP_NOT_FOUND);
        }

        // 4. Crear y persistir la entidad Hija (ProveedorImagen)
        try {
            $imagen = new ProveedorImagen();
            $imagen->setProveedor($proveedor);

            // Asignar el archivo para que VichUploader y LiipImagine lo procesen
            $imagen->setImageFile($uploadedFile);

            // Por defecto, las cargas masivas no marcan la imagen como portada
            $imagen->setIsPortada(false);

            // Calcular orden al final (max + 1) dentro del mismo proveedor
            $proveedorUuid = Uuid::fromString((string) $proveedorId);

            $maxOrden = (int) $em->createQueryBuilder()
                ->select('COALESCE(MAX(i.orden), -1)')
                ->from(ProveedorImagen::class, 'i')
                ->andWhere('IDENTITY(i.proveedor) = :proveedorId')
                ->setParameter('proveedorId', $proveedorUuid, 'uuid')
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