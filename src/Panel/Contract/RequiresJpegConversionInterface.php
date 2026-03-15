<?php

declare(strict_types=1);

namespace App\Panel\Contract;

/**
 * Interfaz Marcadora (Marker Interface) para conversión de medios.
 * * Cualquier entidad que gestione archivos subidos (ej. usando VichUploader y MediaTrait)
 * y que implemente esta interfaz, forzará al VichWebpConversionListener a procesar
 * y guardar la imagen en formato JPEG (.jpg) en lugar del formato WebP predeterminado.
 * * ¿Por qué existe?
 * Es vital para mantener compatibilidad con canales externos o APIs legacy (como Beds24 o Gupshup)
 * que rechazan formatos modernos de imagen o tienen validaciones MIME estrictas.
 */
interface RequiresJpegConversionInterface
{
}