<?php

declare(strict_types=1);

namespace App\Panel\EventListener\Media;

use App\Panel\Contract\RequiresJpegConversionInterface;
use Liip\ImagineBundle\Imagine\Filter\FilterManager;
use Liip\ImagineBundle\Model\Binary;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Vich\UploaderBundle\Event\Event;
use Vich\UploaderBundle\Event\Events;

/**
 * Intercepta cualquier subida de imagen en entidades compatibles ANTES de que Vich la procese.
 * Aplica compresión y conversión de formato dependiendo de las interfaces de la entidad.
 */
#[AsEventListener(event: Events::PRE_UPLOAD)]
class VichWebpConversionListener
{
    // Filtro estándar para optimización interna (WebP)
    private const FILTER_WEBP = 'pms_compress_initial';

    // Filtro legacy para compatibilidad con canales externos como Beds24 (JPEG)
    private const FILTER_JPG  = 'pms_compress_legacy';

    public function __construct(
        private readonly FilterManager $filterManager
    ) {}

    /**
     * Punto de entrada del evento disparado por VichUploader.
     *
     * @param Event $event Evento que contiene la entidad y el mapeo del archivo.
     */
    public function __invoke(Event $event): void
    {
        $object = $event->getObject();
        $mapping = $event->getMapping();

        // 1. Verificamos si la entidad está preparada para manejar medios (Duck Typing de MediaTrait)
        if (!$this->usesMediaTrait($object)) {
            return;
        }

        // 2. Extraemos el archivo físico interceptado
        $fileProperty = $mapping->getFilePropertyName();
        $uploadedFile = $this->getUploadedFile($object, $fileProperty);

        if (!$uploadedFile instanceof UploadedFile) {
            return;
        }

        $mimeType = $uploadedFile->getMimeType();

        // 3. Lógica de Decisión: ¿La entidad exige compatibilidad Legacy (JPEG)?
        $requiresJpg  = $object instanceof RequiresJpegConversionInterface;

        // Asignamos las variables de conversión dinámicamente
        $targetMime   = $requiresJpg ? 'image/jpeg' : 'image/webp';
        $targetExt    = $requiresJpg ? 'jpg' : 'webp';
        $targetFilter = $requiresJpg ? self::FILTER_JPG : self::FILTER_WEBP;

        // 4. Salida rápida: Si no es imagen, o ya está en el formato final, o es un SVG (vectorial), no tocamos nada.
        if (!str_starts_with((string)$mimeType, 'image/') || $mimeType === $targetMime || $mimeType === 'image/svg+xml') {
            return;
        }

        // 5. Ejecutamos la conversión
        try {
            $this->convertImage($object, $uploadedFile, $fileProperty, $targetFilter, $targetExt, $targetMime);
        } catch (\Throwable $e) {
            // Fallback silencioso: Si Imagick falla, el archivo original continuará su ciclo de vida natural.
        }
    }

    /**
     * Procesa la imagen a través de Liip Imagine, la guarda temporalmente con la nueva extensión
     * y la re-inyecta en la entidad reemplazando al archivo original.
     *
     * @param object $entity La entidad que contiene el archivo.
     * @param UploadedFile $originalFile El archivo original tal como llegó en el Request.
     * @param string $propertyName El nombre de la propiedad donde Vich mapea el archivo.
     * @param string $filterName El nombre del filtro configurado en liip_imagine.yaml.
     * @param string $targetExt La extensión resultante ('jpg' o 'webp').
     * @param string $targetMime El tipo MIME resultante ('image/jpeg' o 'image/webp').
     */
    private function convertImage(
        object $entity,
        UploadedFile $originalFile,
        string $propertyName,
        string $filterName,
        string $targetExt,
        string $targetMime
    ): void {
        // A. Convertir el archivo físico en un objeto binario para Liip
        $binary = new Binary(
            file_get_contents($originalFile->getPathname()),
            $originalFile->getMimeType(),
            $originalFile->getClientOriginalExtension()
        );

        // B. Aplicar el filtro de compresión y formato
        $newBinary = $this->filterManager->applyFilter($binary, $filterName);

        // C. Guardar el resultado en el directorio temporal del sistema operativo
        $tmpPath = sys_get_temp_dir() . '/' . uniqid('pms_media_', true) . '.' . $targetExt;
        file_put_contents($tmpPath, $newBinary->getContent());

        // D. Reconstruir el nombre original pero con la nueva extensión
        $originalName = pathinfo($originalFile->getClientOriginalName(), PATHINFO_FILENAME);
        $newFilename = $originalName . '.' . $targetExt;

        // E. Crear una nueva instancia de UploadedFile apuntando al archivo temporal
        // El quinto parámetro (true) es el 'test mode', que permite instanciar el objeto
        // sin que PHP lance un error de validación de seguridad de subida HTTP.
        $newUploadedFile = new UploadedFile(
            $tmpPath,
            $newFilename,
            $targetMime,
            null,
            true
        );

        // F. Inyectar el nuevo archivo procesado de vuelta a la entidad
        $setter = 'set' . ucfirst($propertyName);
        if (method_exists($entity, $setter)) {
            $entity->$setter($newUploadedFile);
        }
    }

    /**
     * Llama al getter correspondiente de la entidad para obtener el archivo instanciado.
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
     * Evalúa si la entidad implementa los métodos provistos por el MediaTrait.
     */
    private function usesMediaTrait(object $object): bool
    {
        return method_exists($object, 'initializeToken') && method_exists($object, 'isImage');
    }
}