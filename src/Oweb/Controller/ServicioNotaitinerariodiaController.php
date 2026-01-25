<?php

namespace App\Oweb\Controller;

use App\Oweb\Entity\ServicioNotaitinerariodia;
use App\Service\GoogleTranslateService;
use Doctrine\ORM\EntityManagerInterface;
use Google\ApiCore\ApiException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controlador para la gestión de Notas de Itinerario de Servicio.
 * Implementa la traducción automática delegando en un servicio de abstracción para cumplir
 * con el estándar de no utilizar vendors directamente en la capa de controlador.
 */
class ServicioNotaitinerariodiaController extends CRUDController
{
    private EntityManagerInterface $entityManager;
    private GoogleTranslateService $translateService;

    /**
     * @param EntityManagerInterface $entityManager Manejador de entidades de Doctrine.
     * @param GoogleTranslateService $translateService Servicio de abstracción para Google Translate V3.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        GoogleTranslateService $translateService
    ) {
        $this->entityManager = $entityManager;
        $this->translateService = $translateService;
    }

    /**
     * Traduce el contenido de la nota de itinerario al idioma actual de la petición.
     * * @param Request $request
     * @return RedirectResponse
     * @throws NotFoundHttpException Si el objeto solicitado no existe.
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

        // Validación para asegurar que no se traduzca sobre el mismo idioma base
        if ($defaultLocale === $targetLocale) {
            $this->addFlash('sonata_flash_error', 'El idioma actual debe ser diferente al idioma por defecto de la aplicación');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        $this->admin->checkAccess('edit', $object);

        /** @var ServicioNotaitinerariodia $notaitinerariodiaDL */
        $notaitinerariodiaDL = $this->entityManager->getRepository(ServicioNotaitinerariodia::class)->find($id);

        // 1. Cargamos el contenido original desde el idioma por defecto
        $notaitinerariodiaDL->setLocale($defaultLocale);
        $this->entityManager->refresh($notaitinerariodiaDL);
        $contenidoDL = $notaitinerariodiaDL->getContenido();

        // 2. Regresamos al idioma destino para la persistencia posterior
        $notaitinerariodiaDL->setLocale($targetLocale);
        $this->entityManager->refresh($notaitinerariodiaDL);

        if (!empty($contenidoDL)) {
            try {
                // Se utiliza el servicio de abstracción para la traducción V3
                $translations = $this->translateService->translate(
                    $contenidoDL,
                    $targetLocale,
                    $defaultLocale
                );

                $contenidoTL = $translations[0] ?? '';

                $object->setContenido($contenidoTL);

                // Persistencia del objeto mediante la lógica de Sonata Admin
                $this->admin->update($object);

                $this->addFlash('sonata_flash_success', 'Nota de día de itinerario traducida correctamente vía API V3');

            } catch (ApiException $e) {
                // Captura de errores de la API (permisos, cuotas o configuración del proyecto)
                $this->addFlash('sonata_flash_error', 'Error en el servicio de traducción: ' . $e->getMessage());
            }
        }

        return new RedirectResponse($this->admin->generateUrl('list'));
    }
}