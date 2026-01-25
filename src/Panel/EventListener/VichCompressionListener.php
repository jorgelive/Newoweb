<?php

namespace App\Panel\EventListener;

use App\Panel\Trait\MediaTrait;
// 1. CAMBIO IMPORTANTE: Usamos FilterManager, no FilterService
use Liip\ImagineBundle\Imagine\Filter\FilterManager;
use Liip\ImagineBundle\Model\Binary;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Vich\UploaderBundle\Event\Event;
use Vich\UploaderBundle\Event\Events;

#[AsEventListener(event: Events::POST_UPLOAD)]
class VichCompressionListener
{
    private const FILTER_INITIAL_COMPRESS = 'pms_compress_initial';

    public function __construct(
        // Inyectamos el Manager, que es el que sabe procesar binarios
        private FilterManager $filterManager
    ) {}

    public function __invoke(Event $event): void
    {
        $object = $event->getObject();

        // 1. Verificar Trait
        $usesTrait = in_array(MediaTrait::class, class_uses($object));
        if (!$usesTrait) return;

        $mapping = $event->getMapping();
        $fileName = $mapping->getFileName($object);

        // 2. Verificar si es imagen (PDFs fuera)
        if (!method_exists($object, 'isImage') || !$object->isImage($fileName)) {
            return;
        }

        $absolutePath = $mapping->getUploadDestination() . DIRECTORY_SEPARATOR . $fileName;

        if (!file_exists($absolutePath)) return;

        try {
            // A. Leemos el archivo físico
            $content = file_get_contents($absolutePath);
            $mimeType = mime_content_type($absolutePath);
            $extension = pathinfo($absolutePath, PATHINFO_EXTENSION);

            // B. Creamos el objeto Binary que Liip necesita
            $binary = new Binary($content, $mimeType, $extension);

            // C. AHORA SÍ: El FilterManager tiene el método applyFilter
            $processedBinary = $this->filterManager->applyFilter($binary, self::FILTER_INITIAL_COMPRESS);

            // D. Sobrescribimos el archivo original con el resultado optimizado
            file_put_contents($absolutePath, $processedBinary->getContent());

        } catch (\Throwable $e) {
            // Si falla (ej. archivo corrupto), dejamos el original sin tocar
            // error_log($e->getMessage());
        }
    }
}