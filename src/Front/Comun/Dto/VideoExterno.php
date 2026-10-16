<?php

declare(strict_types=1);

namespace App\Front\Comun\Dto;

/**
 * Un vídeo de YouTube o Vimeo, leído de su enlace: el proveedor y el identificador, y de ahí el
 * reproductor y la miniatura.
 *
 * - **El reproductor de YouTube es el de `youtube-nocookie.com`**: no deja cookies hasta que se
 *   pulsa reproducir, que es lo que promete la política de cookies de la web.
 * - **La web no lo incrusta al cargar**: enseña la miniatura y pone el reproductor al pulsar
 *   (`templates/front/tours/_video.html.twig`). Un iframe por vídeo en la carga es lento y llama
 *   a terceros sin que nadie lo pida.
 * - Vimeo no da miniatura sin llamar a su API, y no se le llama: su tarjeta va sin imagen.
 *
 * `desdeUrl()` devuelve `null` con cualquier otra cosa: es también el validador del enlace
 * (`ExperienciaMedio::validar()`). Un enlace roto no se guarda.
 */
final readonly class VideoExterno
{
    private function __construct(
        public string $proveedor,
        public string $id,
    ) {
    }

    public static function desdeUrl(?string $url): ?self
    {
        $url = trim((string) $url);

        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return null;
        }

        // youtube.com/watch?v=ID · youtu.be/ID · youtube.com/shorts/ID · /embed/ID · /live/ID
        if (preg_match('#^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:.*&)?v=|shorts/|embed/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})(?:[?&\#/].*)?$#', $url, $m) === 1) {
            return new self('youtube', $m[1]);
        }

        // vimeo.com/123 · vimeo.com/123/abcdef (oculto, con hash) · player.vimeo.com/video/123
        if (preg_match('#^https?://(?:www\.)?(?:player\.)?vimeo\.com/(?:video/)?(\d{6,12})(?:/([A-Za-z0-9]+))?(?:[?\#].*)?$#', $url, $m) === 1) {
            return new self('vimeo', $m[1] . (isset($m[2]) ? '/' . $m[2] : ''));
        }

        return null;
    }

    /** El reproductor a incrustar, con arranque automático: sólo se pone cuando ya se pulsó. */
    public function reproductor(): string
    {
        if ($this->proveedor === 'youtube') {
            return sprintf('https://www.youtube-nocookie.com/embed/%s?autoplay=1&rel=0', $this->id);
        }

        [$id, $hash] = array_pad(explode('/', $this->id, 2), 2, null);

        return sprintf('https://player.vimeo.com/video/%s?autoplay=1%s', $id, $hash !== null ? '&h=' . $hash : '');
    }

    /** La miniatura pública de YouTube; `null` en Vimeo (ver el docblock de la clase). */
    public function miniatura(): ?string
    {
        return $this->proveedor === 'youtube' ? sprintf('https://i.ytimg.com/vi/%s/hqdefault.jpg', $this->id) : null;
    }

    /** El enlace para verlo en su sitio: lo que se abre si no hay JavaScript. */
    public function enlace(): string
    {
        if ($this->proveedor === 'youtube') {
            return 'https://www.youtube.com/watch?v=' . $this->id;
        }

        return 'https://vimeo.com/' . $this->id;
    }
}
