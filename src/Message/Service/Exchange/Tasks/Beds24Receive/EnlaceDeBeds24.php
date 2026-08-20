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
 */
final readonly class EnlaceDeBeds24
{
    private const string BASE = 'https://beds24.com/';

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
