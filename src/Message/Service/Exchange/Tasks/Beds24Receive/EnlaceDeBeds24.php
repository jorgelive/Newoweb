<?php

declare(strict_types=1);

namespace App\Message\Service\Exchange\Tasks\Beds24Receive;

/**
 * Convierte en absolutos los enlaces que Beds24 manda **relativos** dentro del HTML de un
 * mensaje.
 *
 * ## El problema
 *
 * Cuando el huésped adjunta un archivo por Booking.com, la API v2 no manda el archivo: manda
 * un ancla, y su `href` viene **sin host**:
 *
 * ```html
 * <a href="api/booking.com/getattach.php?bookid=88591163&attachid=6924…" target="_blank">attachment</a>
 * ```
 *
 * Eso es correcto dentro de beds24.com y **falso en cualquier otro sitio**. Guardado tal cual,
 * el panel lo resuelve contra su propio dominio y el operador aterriza en un 404 de
 * `panel.openperu.pe/api/booking.com/…`. El enlace no está roto por Beds24: se rompe al
 * sacarlo de su contexto, y por eso se repara **al entrar**.
 *
 * ⚠️ No contradice la «verdad histórica» de `contentExternal`. Una URL relativa sólo significa
 * algo junto al host desde el que se sirvió; conservarla literal fuera de él no preserva el
 * dato, lo destruye. Lo que se guarda es la misma dirección, dicha entera.
 *
 * ## Lo que esto NO arregla
 *
 * El archivo **sigue sin poder descargarse desde el servidor**. `getattach.php` es el sitio
 * legacy, no la API: contestó `error` —5 bytes, con HTTP 200— con el token en cabecera, con el
 * token en la query, con `Authorization: Bearer` y sin autenticación ninguna. Autentica por
 * cookie de sesión del navegador, así que lo abre quien tiene sesión en beds24.com y nadie más.
 * Tampoco lo entrega la v2 por otra vía: `bookings/messages` devuelve nueve claves y ninguna es
 * el adjunto, y `bookings/attachments` responde 500.
 *
 * Es decir: esto hace que el enlace **funcione al pulsarlo**, que es todo lo que se puede hacer
 * sin credenciales de navegador. Ver `docs/Mensajeria.md` §23.
 *
 * ## Y por qué además se aplana a texto
 *
 * El panel **escapa el HTML a propósito**: `formatoAHtml()` protege del texto del huésped y sólo
 * enlaza lo que casa `https?://`. Así que un ancla llega entera y se pinta cruda —`<a href="…`
 * literal en la burbuja—, con la URL suelta subrayada en medio. No se arregla dejando pasar
 * HTML: se arregla **no mandando HTML**.
 *
 * Aplanar tiene un segundo beneficio, que es el que de verdad importa: `strip_tags()` sobre el
 * ancla dejaba la palabra «adjunto» y nada más, así que **el agente no veía que hubiera un
 * enlace**. Aplanado, lo ve.
 *
 * Reescribir el contenido antes de guardarlo no es nuevo aquí: la rama de Airbnb de
 * `Beds24ReceivePersister` ya sustituye el mensaje entero por «📷 Imagen recibida desde Airbnb».
 * Esto es menos destructivo que aquello.
 */
final readonly class EnlaceDeBeds24
{
    private const string BASE = 'https://beds24.com/';

    /**
     * Lo que se aplica al entrar: primero el host, después el aplanado.
     *
     * El orden no es indiferente. Aplanar antes dejaría en el texto la URL corta y sin dominio,
     * que es justo la que no lleva a ninguna parte.
     */
    public static function normalizar(string $html): string
    {
        return self::aplanarAnclas(self::absolutizar($html));
    }

    /**
     * `<a href="X">T</a>` → `T: X`, para que lo pinte el formateador de texto plano.
     *
     * Cuando el texto del ancla no aporta —está vacío, o es la propia URL— se queda sólo la URL:
     * `https://…: https://…` es ruido.
     */
    public static function aplanarAnclas(string $html): string
    {
        if (!str_contains($html, '<a ')) {
            return $html;
        }

        return preg_replace_callback(
            '#<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
            static function (array $m): string {
                $url = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $texto = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                return ($texto === '' || $texto === $url) ? $url : $texto . ': ' . $url;
            },
            $html
        ) ?? $html;
    }

    /**
     * Reescribe a absoluto todo `href`/`src` relativo del HTML.
     *
     * No filtra por `getattach.php` a propósito: el HTML lo redactó Beds24, así que **cualquier**
     * ruta relativa que lleve dentro es suya. Acotarlo al adjunto de hoy dejaría el mismo fallo
     * esperando en el enlace de mañana.
     */
    public static function absolutizar(string $html): string
    {
        if (!str_contains($html, 'href=') && !str_contains($html, 'src=')) {
            return $html;
        }

        return preg_replace_callback(
            '/\b(href|src)\s*=\s*(["\'])(.*?)\2/i',
            static function (array $m): string {
                [, $attr, $comilla, $url] = $m;

                return $attr . '=' . $comilla . (self::esRelativa($url) ? self::BASE . ltrim($url, '/') : $url) . $comilla;
            },
            $html
        ) ?? $html;
    }

    /**
     * ¿Le falta el host?
     *
     * Vacía cuenta como NO relativa: un `href=""` es basura, y anteponerle el dominio lo
     * convertiría en un enlace a la portada de Beds24 —peor que dejarlo inservible, porque
     * parece que lleva a alguna parte—.
     *
     * `//cdn…` tampoco lo es: ya trae host y hereda el esquema.
     */
    private static function esRelativa(string $url): bool
    {
        $url = trim($url);

        if ($url === '' || str_starts_with($url, '//') || str_starts_with($url, '#')) {
            return false;
        }

        // Cualquier esquema —http:, https:, mailto:, data:, tel:— ya es absoluto.
        return preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) !== 1;
    }
}
