<?php

namespace App\Oweb\Controller;

use App\Oweb\Entity\ServicioTarifa;
use App\Service\GoogleTranslateService;
use Doctrine\ORM\EntityManagerInterface;
use Google\ApiCore\ApiException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controlador para la gestión de Tarifas de Servicio.
 * Implementa clonación y traducción automática mediante Google Translate V3.
 */
class ServicioTarifaController extends CRUDController
{
    private EntityManagerInterface $entityManager;
    private GoogleTranslateService $translateService;

    /**
     * @param EntityManagerInterface $entityManager Manejador de entidades.
     * @param GoogleTranslateService $translateService Servicio de traducción configurado con Service Account.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        GoogleTranslateService $translateService
    ) {
        $this->entityManager = $entityManager;
        $this->translateService = $translateService;
    }

    /**
     * Clona una tarifa existente agregando el sufijo (Clone).
     * * @param Request $request
     * @return Response
     */
    public function clonarAction(Request $request): Response
    {
        $object = $this->assertObjectExists($request, true);

        $this->admin->checkAccess('create', $object);

        $newObject = clone $object;
        $newObject->setNombre($object->getNombre() . ' (Clone)');

        $this->admin->create($newObject);

        $this->addFlash('sonata_flash_success', 'Tarifa clonada correctamente');

        return new RedirectResponse($this->admin->generateUrl('list'));
    }

    /**
     * Traduce el título de la tarifa al idioma de la petición actual.
     * * @param Request $request
     * @return RedirectResponse
     * @throws NotFoundHttpException Si no se encuentra la entidad.
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

        // Validación: No traducir si el idioma destino es el mismo que el base
        if ($defaultLocale === $targetLocale) {
            $this->addFlash('sonata_flash_error', 'El idioma actual debe ser diferente al idioma por defecto de la aplicación');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        $this->admin->checkAccess('edit', $object);

        /** @var ServicioTarifa $tarifaDL */
        $tarifaDL = $this->entityManager->getRepository(ServicioTarifa::class)->find($id);

        // 1. Cargar datos del idioma origen
        $tarifaDL->setLocale($defaultLocale);
        $this->entityManager->refresh($tarifaDL);
        $tituloDL = $tarifaDL->getTitulo();

        // 2. Regresar al idioma de la petición para persistir
        $tarifaDL->setLocale($targetLocale);
        $this->entityManager->refresh($tarifaDL);

        if (!empty($tituloDL)) {
            try {
                $translations = $this->translateService->translate(
                    $tituloDL,
                    $targetLocale,
                    $defaultLocale
                );

                $tituloTL = $translations[0] ?? '';

                // Preservar capitalización inicial usando funciones multibyte para Cusco/Español
                if (mb_substr($tituloDL, 0, 1) === mb_strtoupper(mb_substr($tituloDL, 0, 1))) {
                    $tituloTL = $this->mb_ucfirst($tituloTL);
                }

                $object->setTitulo($tituloTL);

                $this->admin->update($object);
                $this->addFlash('sonata_flash_success', 'Tarifa traducida correctamente mediante API V3');

            } catch (ApiException $e) {
                $this->addFlash('sonata_flash_error', 'Error en la comunicación con Google Cloud: ' . $e->getMessage());
            }
        }

        return new RedirectResponse($this->admin->generateUrl('list'));
    }

    /**
     * Helper para capitalizar la primera letra de una cadena multibyte.
     * * @param string $str
     * @param string $encoding
     * @return string
     */
    private function mb_ucfirst(string $str, string $encoding = 'UTF-8'): string
    {
        $firstChar = mb_substr($str, 0, 1, $encoding);
        $then = mb_substr($str, 1, null, $encoding);
        return mb_strtoupper($firstChar, $encoding) . $then;
    }
}