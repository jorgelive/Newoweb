<?php

declare(strict_types=1);

namespace App\Front\Tours\Controller;

use App\Front\Tours\Service\CatalogoWebLector;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * La web pública de tours (openperu.pe): portada, catálogo y ficha de tour.
 *
 * Twig en el servidor y no la SPA de `pax`: un buscador o el previsualizador de WhatsApp tienen
 * que recibir la página ya escrita. Ver docs/PlanWebPublica.md §2.1 y docs/WebPublica.md.
 *
 * El host lo fija `config/routes.yaml` (`front_controllers` → `%app.host.front%`).
 */
final class TourController extends AbstractController
{
    public function __construct(
        private readonly CatalogoWebLector $lector,
        #[Autowire('%pax_host_url%')]
        private readonly string $paxHostUrl,
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
    ) {
    }

    #[Route(path: ['es' => '/', 'en' => '/en'], name: 'front_portada', methods: ['GET'])]
    public function portada(Request $request): Response
    {
        return $this->cacheable($this->render('front/tours/portada.html.twig', [
            'catalogos' => $this->lector->publicados($request->getLocale()),
            'pax_url' => rtrim($this->paxHostUrl, '/'),
        ]));
    }

    #[Route(
        path: ['es' => '/tours/{catalogo}', 'en' => '/en/tours/{catalogo}'],
        name: 'front_catalogo',
        requirements: ['catalogo' => '[a-z0-9]+(?:-[a-z0-9]+)*'],
        methods: ['GET'],
    )]
    public function catalogo(Request $request, string $catalogo): Response
    {
        $web = $this->lector->catalogo($catalogo, $request->getLocale())
            ?? throw new NotFoundHttpException('Catálogo no publicado.');

        return $this->cacheable($this->render('front/tours/catalogo.html.twig', ['catalogo' => $web]));
    }

    /**
     * `{tour}` es «{propuesta}-{slug}». El número identifica; el slug es cosmético y, si no es
     * el de ahora (título cambiado, otro idioma, enlace viejo), se responde 301 a la URL
     * canónica: retitular un tour no rompe los enlaces ya compartidos por WhatsApp.
     */
    #[Route(
        path: ['es' => '/tours/{catalogo}/{tour}', 'en' => '/en/tours/{catalogo}/{tour}'],
        name: 'front_tour',
        requirements: ['catalogo' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'tour' => '\d+(?:-[a-z0-9-]*)?'],
        methods: ['GET'],
    )]
    public function tour(Request $request, string $catalogo, string $tour): Response
    {
        $propuesta = (int) explode('-', $tour, 2)[0];
        $ficha = $this->lector->ficha($catalogo, $propuesta, $request->getLocale())
            ?? throw new NotFoundHttpException('Tour no publicado.');

        $canonico = $ficha->tour->propuesta . '-' . $ficha->tour->slug;
        if ($tour !== $canonico) {
            return $this->redirectToRoute('front_tour', ['catalogo' => $catalogo, 'tour' => $canonico], Response::HTTP_MOVED_PERMANENTLY);
        }

        // El slug cambia con el idioma («montana-de-colores» / «rainbow-mountain»): los hreflang y el
        // selector apuntan a la URL canónica de cada idioma, no a una que responda con 301.
        $alternos = [];
        foreach (SeoController::IDIOMAS as $idioma) {
            $otro = $idioma === $request->getLocale() ? $ficha->tour : $this->lector->catalogo($catalogo, $idioma)?->tour($propuesta);
            if ($otro !== null) {
                $alternos[$idioma] = $this->generateUrl('front_tour', [
                    '_locale' => $idioma,
                    'catalogo' => $catalogo,
                    'tour' => $otro->propuesta . '-' . $otro->slug,
                ], UrlGeneratorInterface::ABSOLUTE_URL);
            }
        }

        return $this->cacheable($this->render('front/tours/tour.html.twig', [
            'ficha' => $ficha,
            'alternos' => $alternos,
            // El itinerario día a día vive en `pax` y no se replica aquí (Cotizaciones.md §6.u).
            'itinerario_url' => sprintf('%s/catalogo/%s/p/%d', rtrim($this->paxHostUrl, '/'), $ficha->catalogo->localizador, $ficha->tour->propuesta),
        ]));
    }

    /**
     * Caché corta y pública: lo publicado cambia pocas veces al día, y cinco minutos bastan para
     * que un pico de tráfico desde una campaña no llegue entero a PHP. Más largo haría que
     * «despublicar» tardase en notarse.
     */
    private function cacheable(Response $response): Response
    {
        // En desarrollo, no: cada cambio de plantilla tardaría cinco minutos en verse.
        if ($this->debug) {
            return $response;
        }
        $response->setPublic();
        $response->setMaxAge(300);
        return $response;
    }
}
