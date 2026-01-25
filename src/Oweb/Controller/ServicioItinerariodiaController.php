<?php

namespace App\Oweb\Controller;

use App\Oweb\Entity\ServicioItinerariodia;
use App\Service\GoogleTranslateService;
use Doctrine\ORM\EntityManagerInterface;
use Google\ApiCore\ApiException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controlador para la gestión de Itinerarios de Servicio.
 * Extiende de CRUDController (Sonata) y gestiona la traducción automática via Google V3.
 */
class ServicioItinerariodiaController extends CRUDController
{
    private EntityManagerInterface $entityManager;
    private GoogleTranslateService $translateService;

    /**
     * @param EntityManagerInterface $entityManager Manejador de entidades.
     * @param GoogleTranslateService $translateService Servicio migrado a Google Translate V3.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        GoogleTranslateService $translateService
    ) {
        $this->entityManager = $entityManager;
        $this->translateService = $translateService;
    }

    /**
     * Acción para traducir el contenido del itinerario al idioma actual de la petición.
     * * @param Request $request
     * @return RedirectResponse
     * @throws NotFoundHttpException Si el objeto no existe.
     * @throws ApiException Si falla la comunicación con Google Cloud.
     */
    public function traducirAction(Request $request): RedirectResponse
    {
        $object = $this->assertObjectExists($request, true);

        if (!$object) {
            throw $this->createNotFoundException(sprintf('unable to find the object with id: %s', $request->get('id')));
        }

        $id = $object->getId();
        $targetLocale = $request->getLocale();
        $defaultLocale = $request->getDefaultLocale();

        // Validación de locales para evitar sobreescritura accidental del idioma base
        if ($defaultLocale === $targetLocale) {
            $this->addFlash('sonata_flash_error', 'El idioma actual debe ser diferente al idioma por defecto de la aplicación');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        $this->admin->checkAccess('edit', $object);

        /** @var ServicioItinerariodia $itinerariodiaDL */
        $itinerariodiaDL = $this->entityManager->getRepository(ServicioItinerariodia::class)->find($id);

        // 1. Obtener contenido en el idioma por defecto (Source)
        $itinerariodiaDL->setLocale($defaultLocale);
        $this->entityManager->refresh($itinerariodiaDL);

        $tituloDL = $itinerariodiaDL->getTitulo();
        $contenidoDL = $itinerariodiaDL->getContenido();

        // 2. Volver al idioma destino para la persistencia
        $itinerariodiaDL->setLocale($targetLocale);
        $this->entityManager->refresh($itinerariodiaDL);

        try {
            // Traducción del Título
            if (!empty($tituloDL)) {
                $translatedTitles = $this->translateService->translate(
                    $tituloDL,
                    $targetLocale,
                    $defaultLocale
                );

                $tituloTexto = $translatedTitles[0] ?? '';

                // Mantener capitalización original si el origen empezaba por Mayúscula
                if (mb_substr($tituloDL, 0, 1) === mb_strtoupper(mb_substr($tituloDL, 0, 1))) {
                    $tituloTexto = mb_ucfirst($tituloTexto);
                }

                $object->setTitulo($tituloTexto);
            }

            // Traducción del Contenido
            if (!empty($contenidoDL)) {
                $translatedContents = $this->translateService->translate(
                    $contenidoDL,
                    $targetLocale,
                    $defaultLocale
                );

                $object->setContenido($translatedContents[0] ?? '');
            }

            $this->admin->update($object);
            $this->addFlash('sonata_flash_success', 'Día de itinerario traducido correctamente mediante API V3');

        } catch (ApiException $e) {
            $this->addFlash('sonata_flash_error', 'Error en Google Translate: ' . $e->getMessage());
        }

        return new RedirectResponse($this->admin->generateUrl('list'));
    }
}

/**
 * Helper para asegurar que mb_ucfirst esté disponible si no existe en el sistema.
 */
if (!function_exists('mb_ucfirst')) {
    function mb_ucfirst(string $str, string $encoding = 'UTF-8'): string
    {
        $firstChar = mb_substr($str, 0, 1, $encoding);
        $then = mb_substr($str, 1, null, $encoding);
        return mb_strtoupper($firstChar, $encoding) . $then;
    }
}