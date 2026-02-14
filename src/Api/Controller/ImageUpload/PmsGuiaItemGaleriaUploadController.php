<?php

declare(strict_types=1);

namespace App\Api\Controller\ImageUpload;

use App\Pms\Entity\PmsGuiaItem;
use App\Pms\Entity\PmsGuiaItemGaleria;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Controlador específico para la galería de PmsGuiaItem.
 * Si vas a tener varios, cambia la ruta base en cada controlador.
 */
#[Route('/api/pms/guia-item-galeria')]
class PmsGuiaItemGaleriaUploadController extends AbstractController
{
    #[Route('/upload', name: 'api_pms_guia_item_galeria_upload', methods: ['POST'])]
    public function upload(Request $request, EntityManagerInterface $em): JsonResponse
    {
        // 1. Obtener datos del Request
        $uploadedFile = $request->files->get('file');
        $itemId = $request->request->get('item_id');

        // 2. Validaciones básicas
        if (!$uploadedFile) {
            return $this->json(['error' => 'No se ha enviado ningún archivo'], 400);
        }

        if (!$itemId) {
            return $this->json(['error' => 'Falta el ID del Item destino'], 400);
        }

        // 3. Buscar la entidad Padre (PmsGuiaItem)
        $item = $em->getRepository(PmsGuiaItem::class)->find($itemId);

        if (!$item) {
            return $this->json(['error' => 'El Item especificado no existe'], 404);
        }

        // 4. Crear y persistir la entidad Hija (PmsGuiaItemGaleria)
        try {
            $galeria = new PmsGuiaItemGaleria();

            // A. Asignar el archivo para que VichUploader lo procese
            $galeria->setImageFile($uploadedFile);

            // B. Vincular con el Padre
            $galeria->setItem($item);

            // C. Generar una descripción por defecto basada en el nombre del archivo
            // Como tu entidad usa un array JSON para idiomas, lo formateamos correctamente.
            $filenameClean = pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME);

            // Estructura compatible con tu trait AutoTranslate / MaestroIdioma
            $galeria->setDescripcion([
                ['language' => 'es', 'content' => $filenameClean]
            ]);

            // D. (Opcional) Asignar orden
            // Podrías contar cuántos hay y sumar 1, o dejarlo en 0.
            $galeria->setOrden(0);

            $em->persist($galeria);
            $em->flush();

            return $this->json([
                'status' => 'ok',
                'id' => $galeria->getId(),
                'imageUrl' => $galeria->getImageName(), // Opcional, para feedback inmediato
                'message' => 'Imagen subida correctamente'
            ]);

        } catch (\Exception $e) {
            // En producción, podrías querer loguear $e->getMessage() y devolver un mensaje genérico
            return $this->json(['error' => 'Error al guardar la imagen: ' . $e->getMessage()], 500);
        }
    }
}