<?php

namespace App\Oweb\Controller;

use App\Oweb\Entity\CotizacionCotpolitica;
use App\Service\GoogleTranslateService;
use Doctrine\ORM\EntityManagerInterface;
use Google\ApiCore\ApiException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controlador para la gestión de Políticas de Cotización.
 * Implementa la migración a Google Translate V3 mediante Service Account.
 */
class CotizacionCotpoliticaController extends CRUDController
{
    private EntityManagerInterface $entityManager;
    private GoogleTranslateService $translateService;

    /**
     * @param EntityManagerInterface $entityManager Manejador de entidades.
     * @param GoogleTranslateService $translateService Servicio de traducción inyectado (V3).
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        GoogleTranslateService $translateService
    ) {
        $this->entityManager = $entityManager;
        $this->translateService = $translateService;
    }

    /**
     * Traduce el contenido de la política al idioma actual de la petición.
     * * @param Request $request
     * @return RedirectResponse
     * @throws NotFoundHttpException Si el objeto no existe.
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

        // Validación de locales para evitar procesamientos redundantes
        if ($defaultLocale === $targetLocale) {
            $this->addFlash('sonata_flash_error', 'El idioma actual debe ser diferente al idioma por defecto de la aplicación');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        $this->admin->checkAccess('edit', $object);

        /** @var CotizacionCotpolitica $cotpoliticaDL */
        $cotpoliticaDL = $this->entityManager->getRepository(CotizacionCotpolitica::class)->find($id);

        // 1. Forzar la carga del contenido en el idioma origen (Source)
        $cotpoliticaDL->setLocale($defaultLocale);
        $this->entityManager->refresh($cotpoliticaDL);
        $contenidoDL = $cotpoliticaDL->getContenido();

        // 2. Regresar al idioma de la petición para la persistencia del objeto traducido
        $cotpoliticaDL->setLocale($targetLocale);
        $this->entityManager->refresh($cotpoliticaDL);

        if (!empty($contenidoDL)) {
            try {
                // Ejecución de la traducción vía API V3
                $translations = $this->translateService->translate(
                    $contenidoDL,
                    $targetLocale,
                    $defaultLocale
                );

                $contenidoTL = $translations[0] ?? '';

                $object->setContenido($contenidoTL);

                // Persistencia de los cambios mediante el administrador de Sonata
                $this->admin->update($object);

                $this->addFlash('sonata_flash_success', 'Política traducida correctamente mediante API V3');

            } catch (ApiException $e) {
                // Captura de errores específicos de la API de Google (cuotas, permisos, etc.)
                $this->addFlash('sonata_flash_error', 'Error en Google Translate Service: ' . $e->getMessage());
            }
        }

        return new RedirectResponse($this->admin->generateUrl('list'));
    }
}