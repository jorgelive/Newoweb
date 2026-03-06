<?php

declare(strict_types=1);

namespace App\Message\Factory;

use App\Message\Entity\MessageAttachment;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\File;

class MessageAttachmentFactory
{
    /**
     * Crea una entidad MessageAttachment a partir de un string base64.
     * Genera un archivo temporal que VichUploader moverá a su destino final durante el flush().
     */
    public function createFromBase64(string $base64Data, string $originalName, string $mimeType): MessageAttachment
    {
        // 1. Limpiar el string base64 si trae la cabecera (ej: "data:image/jpeg;base64,...")
        if (str_contains($base64Data, ',')) {
            $base64Data = explode(',', $base64Data)[1];
        }

        // 2. Decodificar la data
        $decodedData = base64_decode($base64Data, true);
        if ($decodedData === false) {
            throw new RuntimeException('El string proporcionado no es un base64 válido.');
        }

        // 3. Crear un archivo temporal físico en el sistema
        // Usamos un prefijo único para evitar colisiones
        $tmpFileName = uniqid('beds24_attach_', true) . '_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $originalName);
        $tmpFilePath = sys_get_temp_dir() . \DIRECTORY_SEPARATOR . $tmpFileName;

        if (file_put_contents($tmpFilePath, $decodedData) === false) {
            throw new RuntimeException('No se pudo escribir el archivo adjunto temporal.');
        }

        // 4. Instanciar el objeto File de Symfony
        $file = new File($tmpFilePath);

        // 5. Crear la entidad
        $attachment = new MessageAttachment();

        // Al setear el File, VichUploader se engancha y sabe que debe procesarlo
        $attachment->setFile($file);

        // Seteamos la metadata original (Vich también lo haría, pero es buena práctica asegurarlo)
        $attachment->setOriginalName($originalName);
        $attachment->setMimeType($mimeType);
        $attachment->setFileSize($file->getSize());

        return $attachment;
    }
}