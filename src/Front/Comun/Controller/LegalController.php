<?php

declare(strict_types=1);

namespace App\Front\Comun\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use App\Front\Comun\Service\SitioWeb;
use Twig\Environment;

/**
 * Condiciones de las webs públicas. El mecanismo es común; los TEXTOS son de cada web:
 * `templates/front/{sitio}/legal/{pagina}.{idioma}.html.twig` (el sitio lo da `SitioWeb`).
 *
 * Los de `tours/` se trajeron tal cual del PHPTravels heredado (`phptravels_legacy.pt_cms_content`,
 * 06/10/2026). ⚠️ Están escritos para el ALOJAMIENTO y dicen que las excursiones se contratan por
 * cuenta del huésped: para vender tours hay que reescribirlos, y los actuales pasarán a
 * `alojamiento/legal/` cuando nazca centrocuscointi.com. Ver docs/PlanWebPublica.md §4.
 */
final class LegalController extends AbstractController
{
    /** slug => clave de traducción del título. El slug es el mismo en todos los idiomas. */
    public const PAGINAS = [
        'terminos-y-condiciones' => 'legal.terminos',
        'politica-de-cambios-y-devoluciones' => 'legal.devoluciones',
        'privacidad' => 'legal.privacidad',
        'cookies' => 'legal.cookies',
        'programa-esnna' => 'legal.esnna',
        'preguntas-frecuentes' => 'legal.faq',
    ];

    public function __construct(
        private readonly Environment $twig,
        private readonly SitioWeb $sitios,
    ) {
    }

    #[Route(
        path: ['es' => '/legal/{pagina}', 'en' => '/en/legal/{pagina}'],
        name: 'front_legal',
        requirements: ['pagina' => '[a-z0-9-]+'],
        methods: ['GET'],
    )]
    public function pagina(Request $request, string $pagina): Response
    {
        $titulo = self::PAGINAS[$pagina] ?? throw new NotFoundHttpException();
        $sitio = $this->sitios->de($request) ?? throw new NotFoundHttpException();

        // Hay páginas sólo en un idioma (la de cookies sólo existía en inglés): se cae al otro
        // antes que dar un 404 sobre una condición legal enlazada desde el pie.
        $idioma = $request->getLocale();
        $plantilla = null;
        foreach ([$idioma, 'es', 'en'] as $candidato) {
            $ruta = sprintf('front/%s/legal/%s.%s.html.twig', $sitio, $pagina, $candidato);
            if ($this->twig->getLoader()->exists($ruta)) {
                $plantilla = $ruta;
                break;
            }
        }
        if ($plantilla === null) {
            throw new NotFoundHttpException();
        }

        $response = $this->render('front/comun/legal/pagina.html.twig', [
            'layout' => SitioWeb::layout($sitio),
            'titulo' => $titulo,
            'cuerpo' => $plantilla,
            'pagina' => $pagina,
        ]);
        $response->setPublic();
        $response->setMaxAge(3600);
        return $response;
    }
}
