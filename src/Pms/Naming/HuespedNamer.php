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

        // Datos base
        $reservaId = $object->getReserva()?->getId() ?? '0';
        $nombre = $slugger->slug($object->getNombre() . ' ' . $object->getApellido())->lower();

        // TIPO DE DOCUMENTO
        $tipo = match($mapping->getFilePropertyName()) {
            'documentoFile' => 'dni',
            'tamFile'       => 'tam',
            'firmaFile'     => 'firma',
            default         => 'doc'
        };

        // --- CLAVE DEL CACHE BUSTING ---
        // Generamos un hash corto y ÚNICO para esta subida específica.
        // No usamos $object->getToken() porque si el usuario resube la foto,
        // necesitamos que el nombre CAMBIE para obligar al navegador a refrescar.
        $token = bin2hex(random_bytes(4)); // Ejemplo: 'a1b2c3d4'

        // --- LÓGICA DE EXTENSIÓN INTELIGENTE ---
        $file = $mapping->getFile($object);
        $extension = 'bin'; // Fallback por seguridad

        if ($file instanceof UploadedFile) {
            $origExt = strtolower($file->getClientOriginalExtension());
            $imageFormats = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'avif'];

            if (in_array($origExt, $imageFormats)) {
                $extension = 'webp'; // Tu lógica: forzar webp en imágenes
            } else {
                $extension = $origExt;
            }
        }

        // Resultado: 467-jorge-gomez-dni-a1b2c3d4.webp
        return sprintf('%s-%s-%s-%s.%s', $reservaId, $nombre, $tipo, $token, $extension);
    }
}