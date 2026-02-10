<?php

declare(strict_types=1);

namespace App\Pms\Naming;

use App\Pms\Entity\PmsReservaHuesped;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Vich\UploaderBundle\Mapping\PropertyMapping;
use Vich\UploaderBundle\Naming\NamerInterface;

class HuespedNamer implements NamerInterface
{
    public function name($object, PropertyMapping $mapping): string
    {
        if (!$object instanceof PmsReservaHuesped) {
            throw new \InvalidArgumentException('Entidad no soportada. Se esperaba PmsReservaHuesped.');
        }

        $file = $mapping->getFile($object);
        $slugger = new AsciiSlugger();

        // 1. EXTENSIÓN: Confiamos ciegamente en el objeto UploadedFile.
        // Gracias a tu 'VichWebpConversionListener' (PreUpload),
        // si era imagen, aquí YA LLEGA como 'webp'. No forzamos nada.
        $extension = $file->guessExtension() ?? $file->getClientOriginalExtension();

        // 2. DATOS BASE
        // Usamos el Localizador (6 chars) si existe, es más limpio que el UUID.
        // Si no, usamos el Token de Seguridad del MediaTrait.
        $prefix = $object->getReserva()?->getLocalizador() ?? $object->getToken() ?? 'sin-reserva';

        $nombre = $slugger->slug($object->getNombre() . ' ' . $object->getApellido())->lower();

        // 3. TIPO DE DOCUMENTO (Para organizar visualmente)
        $tipo = match($mapping->getFilePropertyName()) {
            'documentoFile' => 'dni',
            'tamFile'       => 'tam',
            'firmaFile'     => 'firma',
            default         => 'doc'
        };

        // 4. CACHE BUSTING / UNICIDAD
        // Generamos un hash corto único para esta subida.
        // Vital para que si cambian la foto, el navegador descargue la nueva.
        $hash = bin2hex(random_bytes(3)); // 6 caracteres extra

        // FORMATO FINAL:
        // "LOC123_juan-perez_dni_a1b2c3.webp"
        // Mucho más legible que usar el UUID completo.
        return sprintf('%s_%s_%s_%s.%s', $prefix, $nombre, $tipo, $hash, $extension);
    }
}