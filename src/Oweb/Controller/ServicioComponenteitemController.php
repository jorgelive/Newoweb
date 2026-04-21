<?php

declare(strict_types=1);

namespace App\Oweb\Controller;

use App\Oweb\Entity\ServicioComponenteitem;
use App\Service\GoogleTranslateService;
use Doctrine\ORM\EntityManagerInterface;
use Google\ApiCore\ApiException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controlador para la gestión de ítems de componentes de servicio.
 * * Implementa operaciones extendidas de Sonata Admin, como la migración
 * a Google Translate V3 mediante una capa de abstracción de servicio (GoogleTranslateService),
 * respetando el principio de responsabilidad única al evitar el uso directo de vendors.
 */
class ServicioComponenteitemController extends CRUDController
{
    private EntityManagerInterface $entityManager;
    private GoogleTranslateService $translateService;

    /**
     * Inyecta las dependencias necesarias para la persistencia y traducción.
     *
     * @param EntityManagerInterface $entityManager    Manejador de entidades de Doctrine.
     * @param GoogleTranslateService $translateService Servicio de abstracción para la API V3 de Google.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        GoogleTranslateService $translateService
    ) {
        $this->entityManager = $entityManager;
        $this->translateService = $translateService;
    }

    /**
     * Traduce el título del ítem del componente al idioma de la petición actual.
     * * Este método obtiene el título en el idioma origen (defaultLocale), consume
     * el servicio de traducción de Google Cloud y persiste el resultado en el
     * idioma destino (targetLocale) preserving la capitalización.
     *
     * @param Request $request La petición HTTP actual que contiene el 'id' y el '_locale' destino.
     * @return RedirectResponse Redirige al listado de Sonata con un mensaje flash.
     * @throws NotFoundHttpException Si el objeto con el ID solicitado no existe.
     * * @example
     * // Al acceder a la ruta: /admin/app/oweb/serviciocomponenteitem/42/traducir?_locale=en
     * // Traducirá el ítem ID 42 desde el idioma base al inglés.
     */
    public function traducirAction(Request $request): RedirectResponse
    {
        // Validación de existencia del objeto en el contexto de la petición
        $object = $this->assertObjectExists($request, true);

        if (!$object) {
            // Casteo explícito a (string) para evitar TypeError en PHP 8.4 si get('id') retorna null
            throw $this->createNotFoundException(sprintf('unable to find the object with id: %s', (string) $request->get('id')));
        }

        $id = $object->getId();
        $targetLocale = $request->getLocale();
        $defaultLocale = $request->getDefaultLocale();

        // Prevención de sobreescritura del idioma base (Source)
        if ($defaultLocale === $targetLocale) {
            $this->addFlash('sonata_flash_error', 'El idioma actual debe ser diferente al idioma por defecto de la aplicación');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        // Validación de permisos de edición en el panel de Sonata
        $this->admin->checkAccess('edit', $object);

        /** @var ServicioComponenteitem $componenteitemDL */
        $componenteitemDL = $this->entityManager->getRepository(ServicioComponenteitem::class)->find($id);

        // 1. Cargamos el contenido original (Source)
        $componenteitemDL->setLocale($defaultLocale);
        $this->entityManager->refresh($componenteitemDL);
        $tituloDL = $componenteitemDL->getTitulo();

        // 2. Regresamos al idioma de la petición para persistir la traducción
        $componenteitemDL->setLocale($targetLocale);
        $this->entityManager->refresh($componenteitemDL);

        if (!empty($tituloDL)) {
            try {
                // Se utiliza el servicio inyectado en lugar del vendor directamente
                $translations = $this->translateService->translate(
                    $tituloDL,
                    $targetLocale,
                    $defaultLocale
                );

                $tituloTL = $translations[0] ?? '';

                // Preservar capitalización inicial con soporte multibyte.
                // Se utiliza la función nativa mb_ucfirst de PHP 8.4.
                if (mb_substr($tituloDL, 0, 1) === mb_strtoupper(mb_substr($tituloDL, 0, 1))) {
                    $tituloTL = mb_ucfirst($tituloTL);
                }

                $object->setTitulo($tituloTL);

                // Actualiza el registro mediante el manejador de ciclo de vida del Admin
                $this->admin->update($object);

                $this->addFlash('sonata_flash_success', 'Ítem de componente traducido correctamente mediante API V3');

            } catch (ApiException $e) {
                $this->addFlash('sonata_flash_error', 'Error en el servicio de traducción: ' . $e->getMessage());
            }
        }

        return new RedirectResponse($this->admin->generateUrl('list'));
    }
}