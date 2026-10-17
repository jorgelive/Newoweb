<?php

declare(strict_types=1);

namespace App\Front\Tours\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * 301 desde las URLs del PHPTravels que sirvió openperu.pe hasta octubre de 2026.
 *
 * Google y algún enlace viejo todavía las piden (`/tours/peru/cusco-cusco-cusco/…`,
 * `/hotels/…`, `/Terms-and-Conditions`). El legacy contestaba `/notfound` con un **200**, que
 * no le dice nada a un buscador; un 301 le dice adónde se mudó, y conserva el poco
 * posicionamiento que hubiera.
 *
 * ⚠️ Prioridad negativa: estas rutas sólo deben ganar cuando no casa ninguna de verdad. La de
 * tours viejos tiene tres tramos y la nueva dos, así que no se pisan, pero el orden lo garantiza.
 */
final class LegadoController extends AbstractController
{
    /** Página legal vieja → slug nuevo de `LegalController::PAGINAS`. */
    private const LEGALES = [
        'Terms-and-Conditions' => 'terminos-y-condiciones',
        'Politica-de-Devoluciones' => 'politica-de-cambios-y-devoluciones',
        'Data-Protection-Privacy' => 'privacidad',
        'cookies-policy' => 'cookies',
        'ESNNA-Program' => 'programa-esnna',
        'FAQ' => 'preguntas-frecuentes',
    ];

    #[Route(path: '/{pagina}', name: 'front_legado_legal', requirements: ['pagina' => 'Terms-and-Conditions|Politica-de-Devoluciones|Data-Protection-Privacy|cookies-policy|ESNNA-Program|FAQ'], priority: -10)]
    public function legal(string $pagina): RedirectResponse
    {
        return $this->redirectToRoute('front_legal', ['pagina' => self::LEGALES[$pagina], '_locale' => 'es'], Response::HTTP_MOVED_PERMANENTLY);
    }

    /**
     * Las mismas, en inglés. El legacy las servía bajo `/en/`, y Bing y Semrush las seguían
     * pidiendo el 17/10/2026 (`/en/Data-Protection-Privacy`, `/en/libro-de-reclamaciones`): daban
     * 404, que el buscador lee como «ya no existe» en vez de «se mudó».
     */
    #[Route(path: '/en/{pagina}', name: 'front_legado_legal_en', requirements: ['pagina' => 'Terms-and-Conditions|Politica-de-Devoluciones|Data-Protection-Privacy|cookies-policy|ESNNA-Program|FAQ'], priority: -10)]
    public function legalEn(string $pagina): RedirectResponse
    {
        return $this->redirectToRoute('front_legal', ['pagina' => self::LEGALES[$pagina], '_locale' => 'en'], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route(path: '/libro-de-reclamaciones.php', name: 'front_legado_libro', priority: -10)]
    public function libro(): RedirectResponse
    {
        return $this->redirectToRoute('front_libro', ['_locale' => 'es'], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route(path: '/en/{viejo}', name: 'front_legado_libro_en', requirements: ['viejo' => 'libro-de-reclamaciones(\.php)?'], priority: -10)]
    public function libroEn(): RedirectResponse
    {
        return $this->redirectToRoute('front_libro', ['_locale' => 'en'], Response::HTTP_MOVED_PERMANENTLY);
    }

    /**
     * Todo lo demás que el legacy servía y aquí no existe: portada. Tours viejos (tres tramos),
     * hoteles (el alojamiento se muda a centrocuscointi.com; mientras, la portada tiene su
     * sección), blog de 2021, cuenta de usuario, extranet de proveedores y las páginas de
     * contenido.
     */
    #[Route(
        path: '/{viejo}',
        name: 'front_legado_portada',
        requirements: ['viejo' => '(tours/[^/]+/[^/]+/.+|hotels(/.*)?|blog(/.*)?|es|login|register|supplier/?|About-Us|contact-us|How-to-Book|Booking-Tips|Our-Partners|Become-Supplier|notfound)'],
        priority: -10,
    )]
    public function portada(): RedirectResponse
    {
        return $this->redirectToRoute('front_portada', ['_locale' => 'es'], Response::HTTP_MOVED_PERMANENTLY);
    }
}
