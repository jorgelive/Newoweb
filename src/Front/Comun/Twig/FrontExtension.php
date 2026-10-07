<?php

declare(strict_types=1);

namespace App\Front\Comun\Twig;

use App\Service\Config\Parametro;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/**
 * Ayudas de plantilla de la web pública (templates/front/).
 */
final class FrontExtension
{
    public function __construct(
        #[Autowire('%app.public_dir%')]
        private readonly mixed $publicDir,
        #[Autowire('%front.whatsapp%')]
        private readonly mixed $whatsapp,
    ) {
    }

    /**
     * Un archivo de `public/` con `?v=<mtime>`. La hoja de estilos no pasa por ningún build, así
     * que es la forma de que un cambio desplegado no se quede detrás de la caché del navegador.
     */
    #[AsTwigFunction('front_asset')]
    public function asset(string $ruta): string
    {
        $ruta = '/' . ltrim($ruta, '/');
        $archivo = Parametro::texto($this->publicDir, 'app.public_dir') . $ruta;
        $mtime = is_file($archivo) ? filemtime($archivo) : false;

        return $mtime !== false ? $ruta . '?v=' . $mtime : $ruta;
    }

    /** Enlace de WhatsApp con el mensaje ya escrito. */
    #[AsTwigFunction('front_whatsapp')]
    public function whatsapp(?string $mensaje = null): string
    {
        $numero = preg_replace('/\D+/', '', Parametro::texto($this->whatsapp, 'front.whatsapp')) ?? '';
        $url = 'https://wa.me/' . $numero;

        return $mensaje !== null && $mensaje !== '' ? $url . '?text=' . rawurlencode($mensaje) : $url;
    }

    /** Recorta en palabra completa para una meta descripción (~158 caracteres es lo que se ve). */
    #[AsTwigFilter('front_recorte')]
    public function recorte(?string $texto, int $largo = 158): string
    {
        $texto = trim((string) preg_replace('/\s+/u', ' ', (string) $texto));
        if (mb_strlen($texto) <= $largo) {
            return $texto;
        }
        $corte = mb_substr($texto, 0, $largo - 1);
        $espacio = mb_strrpos($corte, ' ');

        return rtrim($espacio !== false && $espacio > $largo * 0.6 ? mb_substr($corte, 0, $espacio) : $corte, ' ,.;:') . '…';
    }
}
