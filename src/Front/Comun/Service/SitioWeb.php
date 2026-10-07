<?php

declare(strict_types=1);

namespace App\Front\Comun\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * ¿De qué web pública es esta petición? Lo decide el dominio, igual que el router.
 *
 * Lo necesita lo que es COMÚN a los dos fronts (Libro de Reclamaciones, páginas legales): la
 * pieza es la misma, pero cada web tiene su cabecera, su marca y sus textos legales. Cada sitio
 * es una carpeta de plantillas: `templates/front/{sitio}/layout.html.twig` y
 * `templates/front/{sitio}/legal/`.
 *
 * Hoy sólo existe `tours` (openperu.pe). `alojamiento` (centrocuscointi.com) se añade aquí con su
 * host cuando nazca su front — ver docs/WebPublica.md §8.
 */
final class SitioWeb
{
    public const TOURS = 'tours';

    public function __construct(
        #[Autowire('%app.host.front%')]
        private readonly string $hostTours,
    ) {
    }

    /** El sitio del dominio pedido, o null si no es de ninguna web pública. */
    public function de(Request $request): ?string
    {
        return match ($request->getHost()) {
            $this->hostTours => self::TOURS,
            default => null,
        };
    }

    public static function layout(string $sitio): string
    {
        return sprintf('front/%s/layout.html.twig', $sitio);
    }
}
