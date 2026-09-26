<?php

declare(strict_types=1);

namespace App\Panel\Naming;

use Vich\UploaderBundle\Mapping\PropertyMapping;
use Vich\UploaderBundle\Naming\NamerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Namer Cronológico Seguro.
 * Formato: YYYY-MM-DD_HH-MM-SS_TOKEN_RANDOM.ext
 * Ej: "2026-02-02_18-30-15_5f3a2b1c_a1b2.webp"
 *
 * @implements NamerInterface<object>
 */
class MediaTokenNamer implements NamerInterface
{
    public function name($object, PropertyMapping $mapping): string
    {
        // Vich sólo llama al namer cuando hay fichero; si llegara sin él, que lo diga aquí y no
        // como «Call to a member function guessExtension() on null» unas líneas abajo.
        $file = $mapping->getFile($object)
            ?? throw new \InvalidArgumentException('El namer de medios se llamó sin fichero.');

        // 1. EXTENSIÓN
        // Respetamos si el listener PreUpload ya lo convirtió a 'webp'
        // ⚠️ `getClientOriginalExtension()` sólo existe en `UploadedFile`. `getFile()` puede
        // devolver un `File` normal —pasa cuando Vich reprocesa uno ya guardado— y ahí esto
        // reventaba. `guessExtension()` sí está en los dos, así que se usa el original sólo
        // cuando de verdad hay uno.
        $extension = $file->guessExtension()
            ?? ($file instanceof UploadedFile ? $file->getClientOriginalExtension() : 'bin');

        // 2. FECHA DE SUBIDA (Sanitizada para URL)
        // Usamos guiones bajos y medios para evitar espacios y dos puntos
        $timestamp = date('Y-m-d_H-i-s');

        // 3. OBTENER TOKEN (Del MediaTrait)
        $token = 'media';
        if (method_exists($object, 'getToken')) {
            // Autocuración: Si no tiene token, lo creamos
            if ($object->getToken() === null && method_exists($object, 'initializeToken')) {
                $object->initializeToken();
            }
            $leido = $object->getToken();
            $token = is_string($leido) ? $leido : 'general';
        }

        // 4. RANDOM CORTO (Anti-colisión)
        // Agregamos 2 bytes (4 caracteres) por si suben 2 fotos en el mismo segundo
        $uniq = bin2hex(random_bytes(2));

        // 5. RETORNO
        // Formato: 2026-02-02_18-05-00_TOKEN_a1b2.webp
        return sprintf('%s_%s_%s.%s', $timestamp, $token, $uniq, $extension);
    }
}