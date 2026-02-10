<?php

declare(strict_types=1);

namespace App\Panel\EventListener\Media;

use Liip\ImagineBundle\Imagine\Filter\FilterManager;
use Liip\ImagineBundle\Model\Binary;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Vich\UploaderBundle\Event\Event;
use Vich\UploaderBundle\Event\Events;

/**
 * VichWebpConversionListener.
 * Intercepta cualquier subida de imagen en entidades con MediaTrait,
 * la convierte a WebP y cambia su extensión ANTES de que Vich la procese.
 */
#[AsEventListener(event: Events::PRE_UPLOAD)]
class VichWebpConversionListener
{
    // El nombre del filtro en liip_imagine.yaml que tiene 'format: webp'
    private const FILTER_NAME = 'pms_compress_initial';

    public function __construct(
        private FilterManager $filterManager
    ) {}

    public function __invoke(Event $event): void
    {
        $object = $event->getObject();
        $mapping = $event->getMapping();

        // 1. Verificar si la entidad usa MediaTrait (Duck Typing)
        // Buscamos métodos específicos del trait para asegurar compatibilidad
        if (!$this->usesMediaTrait($object)) {
            return;
        }

        // 2. Obtener el archivo subido
        $fileProperty = $mapping->getFilePropertyName(); // ej: 'imageFile', 'documentoFile'
        $uploadedFile = $this->getUploadedFile($object, $fileProperty);

        // 3. Validaciones: Debe ser una instancia válida de UploadedFile
        if (!$uploadedFile instanceof UploadedFile) {
            return;
        }

        // 4. Validar tipo MIME (Solo imágenes, ignorar lo que ya es WebP o SVG)
        $mimeType = $uploadedFile->getMimeType();
        if (!str_starts_with($mimeType, 'image/') || $mimeType === 'image/webp' || $mimeType === 'image/svg+xml') {
            return;
        }

        // 5. CONVERSIÓN
        try {
            $this->convertToWebp($object, $uploadedFile, $fileProperty);
        } catch (\Throwable $e) {
            // Si falla la conversión (ej: Imagick no disponible), dejamos pasar el original
            // para no romper el flujo del usuario.
        }
    }

    private function convertToWebp(object $entity, UploadedFile $originalFile, string $propertyName): void
    {
        // A. Leer binario original
        $binary = new Binary(
            file_get_contents($originalFile->getPathname()),
            $originalFile->getMimeType(),
            $originalFile->getClientOriginalExtension()
        );

        // B. Procesar con Liip (Debe tener format: webp en el YAML)
        $newBinary = $this->filterManager->applyFilter($binary, self::FILTER_NAME);

        // C. Crear archivo temporal .webp
        $tmpPath = sys_get_temp_dir() . '/' . uniqid('pms_webp_', true) . '.webp';
        file_put_contents($tmpPath, $newBinary->getContent());

        // D. Calcular nuevo nombre (archivo.jpg -> archivo.webp)
        $originalName = pathinfo($originalFile->getClientOriginalName(), PATHINFO_FILENAME);
        $newFilename = $originalName . '.webp';

        // E. Crear el nuevo UploadedFile simulado
        $newUploadedFile = new UploadedFile(
            $tmpPath,
            $newFilename,
            'image/webp',
            null,
            true // test mode = true evita que Symfony intente mover el archivo tmp del sistema
        );

        // F. Reemplazar el archivo en la entidad (Inyección)
        $setter = 'set' . ucfirst($propertyName);
        if (method_exists($entity, $setter)) {
            $entity->$setter($newUploadedFile);
        }
    }

    /**
     * Obtiene el archivo usando el getter de la propiedad.
     */
    private function getUploadedFile(object $object, string $property): ?object
    {
        $getter = 'get' . ucfirst($property);
        if (method_exists($object, $getter)) {
            return $object->$getter();
        }
        return null;
    }

    /**
     * Verifica si la entidad implementa la lógica de MediaTrait.
     */
    private function usesMediaTrait(object $object): bool
    {
        return method_exists($object, 'initializeToken') && method_exists($object, 'isImage');
    }
}