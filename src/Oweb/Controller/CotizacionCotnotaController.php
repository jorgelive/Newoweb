<?php

namespace App\Oweb\Controller;

use App\Oweb\Entity\CotizacionCotnota;
use App\Service\GoogleTranslateService;
use Doctrine\ORM\EntityManagerInterface;
use Google\ApiCore\ApiException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controlador para la gestión de Notas de Cotización.
 * Implementa la traducción automática delegada en un servicio de abstracción para cumplir
 * con el estándar de no utilizar vendors directamente en la capa de controlador.
 */
class CotizacionCotnotaController extends CRUDController
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
     * Traduce el título y contenido de la nota al idioma actual de la petición.
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

        /** @var CotizacionCotnota $cotnotaDL */
        $cotnotaDL = $this->entityManager->getRepository(CotizacionCotnota::class)->find($id);

        // 1. Cargar el contenido original desde el idioma por defecto
        $cotnotaDL->setLocale($defaultLocale);
        $this->entityManager->refresh($cotnotaDL);

        $tituloDL = $cotnotaDL->getTitulo();
        $contenidoDL = $cotnotaDL->getContenido();

        // 2. Regresar al locale destino para persistir la traducción
        $cotnotaDL->setLocale($targetLocale);
        $this->entityManager->refresh($cotnotaDL);

        try {
            // Traducción del Título
            if (!empty($tituloDL)) {
                $translatedTitles = $this->translateService->translate(
                    $tituloDL,
                    $targetLocale,
                    $defaultLocale
                );

                $tituloTL = $translatedTitles[0] ?? '';

                // Preservar capitalización inicial con soporte multibyte
                if (mb_substr($tituloDL, 0, 1) === mb_strtoupper(mb_substr($tituloDL, 0, 1))) {
                    $tituloTL = $this->mb_ucfirst($tituloTL);
                }

                $object->setTitulo($tituloTL);
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

            // Persistencia del objeto mediante el administrador de Sonata
            $this->admin->update($object);
            $this->addFlash('sonata_flash_success', 'Nota de cotización traducida correctamente mediante API V3');

        } catch (ApiException $e) {
            // Captura de errores de la API (permisos, cuotas o configuración)
            $this->addFlash('sonata_flash_error', 'Error en el servicio de traducción: ' . $e->getMessage());
        }

        return new RedirectResponse($this->admin->generateUrl('list'));
    }

    /**
     * Helper para capitalizar la primera letra de un string con soporte UTF-8.
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