<?php

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
 * Implementa la migración a Google Translate V3 mediante una capa de abstracción de servicio
 * para evitar el uso directo de vendors en el controlador.
 */
class ServicioComponenteitemController extends CRUDController
{
    private EntityManagerInterface $entityManager;
    private GoogleTranslateService $translateService;

    /**
     * @param EntityManagerInterface $entityManager Manejador de entidades.
     * @param GoogleTranslateService $translateService Servicio de abstracción para la API V3.
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

        if ($defaultLocale === $targetLocale) {
            $this->addFlash('sonata_flash_error', 'El idioma actual debe ser diferente al idioma por defecto de la aplicación');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

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

                // Preservar capitalización inicial con soporte multibyte (estándar para Cusco)
                if (mb_substr($tituloDL, 0, 1) === mb_strtoupper(mb_substr($tituloDL, 0, 1))) {
                    $tituloTL = $this->mb_ucfirst($tituloTL);
                }

                $object->setTitulo($tituloTL);
                $this->admin->update($object);

                $this->addFlash('sonata_flash_success', 'Ítem de componente traducido correctamente mediante API V3');

            } catch (ApiException $e) {
                $this->addFlash('sonata_flash_error', 'Error en el servicio de traducción: ' . $e->getMessage());
            }
        }

        return new RedirectResponse($this->admin->generateUrl('list'));
    }

    /**
     * Capitaliza la primera letra de un string con soporte para UTF-8.
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