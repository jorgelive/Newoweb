<?php

declare(strict_types=1);

namespace App\Front\Tours\Controller;

use App\Front\Tours\Service\CatalogoWebLector;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * `robots.txt` y `sitemap.xml` de la web pública. Van por controlador y no como archivos en
 * `public/` porque `public/` lo comparten todos los hosts (api, util, pax…): un `robots.txt`
 * estático le diría lo mismo a todos.
 */
final class SeoController extends AbstractController
{
    /** Los idiomas con interfaz traducida (translations/front.*.yaml y front_tours.*.yaml). */
    public const IDIOMAS = ['es', 'en'];

    public function __construct(private readonly CatalogoWebLector $lector)
    {
    }

    #[Route(path: '/robots.txt', name: 'front_robots', methods: ['GET'])]
    public function robots(): Response
    {
        $sitemap = $this->generateUrl('front_sitemap', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $texto = "User-agent: *\nDisallow: /libro-de-reclamaciones/hoja/\nDisallow: /en/complaints-book/sheet/\n\nSitemap: {$sitemap}\n";

        return new Response($texto, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=86400']);
    }

    #[Route(path: '/sitemap.xml', name: 'front_sitemap', methods: ['GET'])]
    public function sitemap(): Response
    {
        $urls = [];
        foreach (self::IDIOMAS as $idioma) {
            $urls[] = $this->generateUrl('front_portada', ['_locale' => $idioma], UrlGeneratorInterface::ABSOLUTE_URL);
            foreach ($this->lector->publicados($idioma) as $catalogo) {
                $urls[] = $this->generateUrl('front_catalogo', ['_locale' => $idioma, 'catalogo' => $catalogo->slug], UrlGeneratorInterface::ABSOLUTE_URL);
                foreach ($catalogo->tours as $tour) {
                    $urls[] = $this->generateUrl('front_tour', [
                        '_locale' => $idioma,
                        'catalogo' => $catalogo->slug,
                        'tour' => $tour->propuesta . '-' . $tour->slug,
                    ], UrlGeneratorInterface::ABSOLUTE_URL);
                }
            }
        }

        $response = $this->render('front/tours/sitemap.xml.twig', ['urls' => $urls]);
        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');
        $response->setPublic();
        $response->setMaxAge(3600);
        return $response;
    }
}
