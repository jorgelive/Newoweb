<?php

namespace App\Pms\Naming;

use App\Pms\Entity\PmsReservaHuesped;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Vich\UploaderBundle\Mapping\PropertyMapping;
use Vich\UploaderBundle\Naming\NamerInterface;

class HuespedNamer implements NamerInterface
{
    public function name($object, PropertyMapping $mapping): string
    {
        if (!$object instanceof PmsReservaHuesped) {
            throw new \InvalidArgumentException('Entidad no soportada');
        }

        $slugger = new AsciiSlugger();
        $reservaId = $object->getReserva()?->getId() ?? '0';
        $nombre = $slugger->slug($object->getNombre() . ' ' . $object->getApellido())->lower();

        // Obtenemos el token (si no existe, null fallback)
        $token = method_exists($object, 'getToken') ? $object->getToken() : bin2hex(random_bytes(4));
        if (!$token) $token = bin2hex(random_bytes(4));

        $tipo = match($mapping->getFilePropertyName()) {
            'documentoFile' => 'dni',
            'tamFile'       => 'tam',
            'firmaFile'     => 'firma',
            default         => 'doc'
        };

        // --- LÓGICA DE EXTENSIÓN INTELIGENTE ---
        // Recuperamos el archivo real que se está subiendo para ver qué es
        $file = $mapping->getFile($object);
        $extension = 'bin'; // Fallback

        if ($file instanceof UploadedFile) {
            $origExt = strtolower($file->getClientOriginalExtension());

            // Lista de formatos que SÍ vamos a convertir a WebP
            $imageFormats = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'avif'];

            if (in_array($origExt, $imageFormats)) {
                // Si es imagen, forzamos .webp (porque el Listener lo convertirá)
                $extension = 'webp';
            } else {
                // Si es PDF, Excel, Word, etc., respetamos la original
                $extension = $origExt;
            }
        }

        return sprintf('%s-%s-%s-%s.%s', $reservaId, $nombre, $tipo, $token, $extension);
    }
}