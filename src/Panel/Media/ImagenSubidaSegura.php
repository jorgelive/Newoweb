<?php

declare(strict_types=1);

namespace App\Panel\Media;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * El portero de las subidas de imagen que acaban en `public/`.
 *
 * 🔒 **Por qué existe:** las fotos de galería se guardan bajo `public/carga/…` y las sirve nginx
 * como archivos estáticos, y **todos los hosts comparten ese `public/`** —`openperu.pe`,
 * `util.`, `pax.`, `panel.`, `api.`—. Un archivo subido ahí es, por tanto, contenido del MISMO
 * origen que el panel del operador.
 *
 * Qué pasaba sin esto:
 *
 * - `VichWebpConversionListener` **se aparta** cuando el MIME no empieza por `image/` o es
 *   `image/svg+xml`, y también cuando Imagick falla (su `catch` es silencioso a propósito). En
 *   los tres casos el archivo original sigue su camino **intacto**.
 * - `MediaTokenNamer` conserva la extensión adivinada del contenido, así que un SVG se guarda
 *   como `.svg` y un HTML como `.html`.
 *
 * Resultado: un `<svg>` con `<script>` colgado de `util.openperu.pe` es XSS almacenado en el
 * origen donde vive la sesión del operador. Ejecutar PHP no se puede —nginx sólo encamina
 * `index.php`, comprobado en producción el 16/10/2026— pero eso es una defensa de la
 * configuración del servidor, no de la aplicación.
 *
 * ⚠️ **Y no vale ponerlo como `#[Assert\Image]` en la entidad**: en las altas por multipart el
 * binario lo recoge el *processor* de API Platform, que corre DESPUÉS del validador. Cuando la
 * validación mira, la propiedad del archivo todavía está vacía. Por eso el portero se llama desde
 * el processor, que es el único sitio por donde el archivo pasa de verdad.
 */
final class ImagenSubidaSegura
{
    /**
     * Los formatos que de verdad son una imagen rasterizada y que el navegador no ejecuta.
     *
     * **SVG no está, y es deliberado**: es un documento XML con scripts, no un mapa de píxeles.
     * Si algún día hace falta (un logo vectorial), no se añade aquí: se sirve desde fuera de
     * `public/` o con `Content-Disposition: attachment`.
     *
     * @var list<string>
     */
    public const array MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/avif',
        'image/heic',
        'image/heif',
    ];

    /**
     * Deja pasar la subida o la rechaza con un 422 que dice qué llegó.
     *
     * El MIME se lee del CONTENIDO (`getMimeType()`, que pregunta a fileinfo), nunca de lo que
     * declara el cliente (`getClientMimeType()`): esa cabecera la escribe quien sube.
     */
    public static function verificar(UploadedFile $subida): void
    {
        $mime = $subida->getMimeType();

        if ($mime === null || !\in_array($mime, self::MIMES, true)) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Eso no es una foto que podamos publicar (%s). Sube un JPG, PNG, WEBP, GIF, AVIF o HEIC.',
                $mime ?? 'formato desconocido',
            ));
        }
    }
}
