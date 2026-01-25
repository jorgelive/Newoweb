<?php

namespace App\Oweb\Controller;

use App\Oweb\Entity\ReservaUnitcaracteristica;
use App\Service\GoogleTranslateService;
use Doctrine\ORM\EntityManagerInterface;
use Google\ApiCore\ApiException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controlador para la gestión de características de unidades de reserva.
 * Implementa la traducción automática delegando en GoogleTranslateService para cumplir
 * con el estándar de no utilizar vendors directamente en la capa de controlador.
 */
class ReservaUnitcaracteristicaController extends CRUDController
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
     * Traduce el contenido de la característica de la unidad al idioma de la petición.
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

        // Validación para asegurar que el idioma destino sea distinto al base
        if ($defaultLocale === $targetLocale) {
            $this->addFlash('sonata_flash_error', 'El idioma actual debe ser diferente al idioma por defecto de la aplicación');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        $this->admin->checkAccess('edit', $object);

        /** @var ReservaUnitcaracteristica $unitcaracteristicaDL */
        $unitcaracteristicaDL = $this->entityManager->getRepository(ReservaUnitcaracteristica::class)->find($id);

        // 1. Cargar el contenido original desde el locale por defecto
        $unitcaracteristicaDL->setLocale($defaultLocale);
        $this->entityManager->refresh($unitcaracteristicaDL);
        $contenidoDL = $unitcaracteristicaDL->getContenido();

        // 2. Cambiar al locale de destino para la persistencia de la traducción
        $unitcaracteristicaDL->setLocale($targetLocale);
        $this->entityManager->refresh($unitcaracteristicaDL);

        if (!empty($contenidoDL)) {
            try {
                // Traducción mediante el servicio de abstracción para cumplir con el estándar arquitectónico solicitado.
                $translations = $this->translateService->translate(
                    $contenidoDL,
                    $targetLocale,
                    $defaultLocale
                );

                $contenidoTL = $translations[0] ?? '';

                $object->setContenido($contenidoTL);

                // Actualización del objeto mediante el administrador de Sonata
                $this->admin->update($object);

                $this->addFlash('sonata_flash_success', 'La característica de la unidad se ha traducido correctamente mediante API V3');

            } catch (ApiException $e) {
                // Captura de errores específicos de la API (configuración de proyecto, cuotas o Service Account)
                $this->addFlash('sonata_flash_error', 'Error en el servicio de traducción: ' . $e->getMessage());
            }
        }

        return new RedirectResponse($this->admin->generateUrl('list'));
    }
}