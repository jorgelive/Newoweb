<?php

declare(strict_types=1);

namespace App\Front\Comun\Controller;

use App\Front\Comun\Entity\LibroReclamacion;
use App\Front\Comun\Form\LibroReclamacionType;
use App\Front\Comun\Service\LibroReclamacionesService;
use App\Front\Comun\Service\SitioWeb;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Libro de Reclamaciones virtual. Ver docs/WebPublica.md §5.
 *
 * Sin sesión en ningún momento (CSRF sin estado, ver `framework.yaml`): así funciona igual en
 * openperu.pe que en centrocuscointi.com, donde la cookie de sesión de `.openperu.pe` no vale.
 */
final class LibroReclamacionesController extends AbstractController
{
    /** Lo que vive el enlace firmado a la hoja recién presentada. */
    private const VIGENCIA_ENLACE = 86400;

    public function __construct(
        private readonly LibroReclamacionesService $libro,
        private readonly EntityManagerInterface $em,
        private readonly UriSigner $firmador,
        private readonly SitioWeb $sitios,
    ) {
    }

    #[Route(
        path: ['es' => '/libro-de-reclamaciones', 'en' => '/en/complaints-book'],
        name: 'front_libro',
        methods: ['GET', 'POST'],
    )]
    public function formulario(Request $request): Response
    {
        $sitio = $this->sitios->de($request) ?? throw new NotFoundHttpException();
        $hoja = new LibroReclamacion();
        $form = $this->createForm(LibroReclamacionType::class, $hoja);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $trampa = $form->get('sitioWeb')->getData();
            if (is_string($trampa) && $trampa !== '') {
                // Un robot: se le contesta como si hubiera ido bien y no se guarda nada.
                return $this->redirectToRoute('front_portada');
            }

            $this->libro->registrar($hoja, $request->getHost(), $request->getClientIp());

            // PRG con enlace firmado, no con flash: el flash necesita sesión, y este formulario
            // funciona sin ella. La firma caduca; quien la tiene es quien acaba de presentarla.
            $url = $this->generateUrl('front_libro_hoja', ['id' => (string) $hoja->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
            return $this->redirect($this->firmador->sign($url, new \DateTimeImmutable('+' . self::VIGENCIA_ENLACE . ' seconds')));
        }

        return $this->render('front/comun/libro/formulario.html.twig', [
            'layout' => SitioWeb::layout($sitio),
            'form' => $form,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route(
        path: ['es' => '/libro-de-reclamaciones/hoja/{id}', 'en' => '/en/complaints-book/sheet/{id}'],
        name: 'front_libro_hoja',
        requirements: ['id' => '[0-9a-f-]{36}'],
        methods: ['GET'],
    )]
    public function hoja(Request $request, string $id): Response
    {
        $sitio = $this->sitios->de($request);
        if ($sitio === null || !$this->firmador->checkRequest($request)) {
            throw new NotFoundHttpException();
        }
        $hoja = $this->em->find(LibroReclamacion::class, Uuid::fromString($id))
            ?? throw new NotFoundHttpException();

        $response = $this->render('front/comun/libro/hoja.html.twig', [
            'layout' => SitioWeb::layout($sitio),
            'hoja' => $hoja,
        ]);
        // Datos personales: que nada intermedio la guarde.
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');
        return $response;
    }
}
