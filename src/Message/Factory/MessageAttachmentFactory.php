<?php

declare(strict_types=1);

namespace App\Message\Factory;

use App\Message\Entity\MessageAttachment;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use const DIRECTORY_SEPARATOR;

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
        $tmpFileName = uniqid('chat_attach_', true) . '_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $originalName);
        $tmpFilePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $tmpFileName;

        if (file_put_contents($tmpFilePath, $decodedData) === false) {
            throw new RuntimeException('No se pudo escribir el archivo adjunto temporal.');
        }

        // 🔥 4. TRUCO CLAVE: Instanciar como UploadedFile en modo "$test = true"
        // Esto engaña a VichUploader para que trate el archivo programático
        // exactamente igual que si hubiera entrado por un <input type="file"> tradicional.
        $file = new UploadedFile(
            $tmpFilePath,        // Ruta del archivo físico temporal
            $originalName,       // Nombre original que trajo el front
            $mimeType,           // Tipo MIME que trajo el front
            null,                // Sin código de error
            true                 // Modo TEST activado: bypass a la seguridad is_uploaded_file()
        );

        // 5. Crear la entidad
        $attachment = new MessageAttachment();

        // Al setear el UploadedFile, VichUploader se engancha y lo procesará en el prePersist
        $attachment->setFile($file);

        // Seteamos la metadata original
        $attachment->setOriginalName($originalName);
        $attachment->setMimeType($mimeType);
        $attachment->setFileSize($file->getSize());

        return $attachment;
    }
}