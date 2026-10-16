<?php

declare(strict_types=1);

namespace App\Front\Tours\Controller;

use App\Front\Comun\Service\ExperienciaWebLector;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * «Experiencias reales»: los álbumes de fotos y vídeos de viajes ya operados (docs/WebPublica.md,
 * docs/PlanWebPublica.md Fase 3). Lo que se enseña lo decide `ExperienciaWebLector`: sólo lo
 * publicado CON autorización de las familias.
 *
 * ⚠️ `/experiencias` es una ruta de un tramo en la raíz, donde también viven los catálogos: está
 * en `CotizacionCatalogo::SLUGS_RESERVADOS` y en `TourController::SLUG_CATALOGO`.
 */
final class ExperienciaController extends AbstractController
{
    public function __construct(
        private readonly ExperienciaWebLector $lector,
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
    ) {
    }

    #[Route(path: ['es' => '/experiencias', 'en' => '/en/experiences'], name: 'front_experiencias', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->cacheable($this->render('front/tours/experiencias.html.twig', [
            'albumes' => $this->lector->publicados($request->getLocale()),
        ]));
    }

    #[Route(
        path: ['es' => '/experiencias/{album}', 'en' => '/en/experiences/{album}'],
        name: 'front_experiencia',
        requirements: ['album' => '[a-z0-9]+(?:-[a-z0-9]+)*'],
        methods: ['GET'],
    )]
    public function album(Request $request, string $album): Response
    {
        $web = $this->lector->album($album, $request->getLocale())
            ?? throw new NotFoundHttpException('Álbum no publicado.');

        $otros = array_values(array_filter(
            $this->lector->publicados($request->getLocale()),
            static fn ($a): bool => $a->slug !== $web->slug,
        ));

        return $this->cacheable($this->render('front/tours/experiencia.html.twig', [
            'album' => $web,
            'otros' => array_slice($otros, 0, 3),
        ]));
    }

    /** Cinco minutos de caché pública, como el resto de la web; en desarrollo, ninguna. */
    private function cacheable(Response $response): Response
    {
        if (!$this->debug) {
            $response->setPublic();
            $response->setMaxAge(300);
        }

        return $response;
    }
}
