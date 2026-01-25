<?php

namespace App\Oweb\Controller;

use App\Oweb\Entity\MaestroMedio;
use App\Service\GoogleTranslateService;
use Doctrine\ORM\EntityManagerInterface;
use Google\ApiCore\ApiException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controlador para la gestión de MaestroMedio (Multimedia).
 * Implementa traducción automática V3 y carga de archivos mediante AJAX con procesamiento de base64.
 */
class MaestroMedioController extends CRUDController
{
    private EntityManagerInterface $entityManager;
    private GoogleTranslateService $translateService;

    /**
     * @param EntityManagerInterface $entityManager Manejador de persistencia.
     * @param GoogleTranslateService $translateService Servicio de traducción configurado para el entorno.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        GoogleTranslateService $translateService
    ) {
        $this->entityManager = $entityManager;
        $this->translateService = $translateService;
    }

    /**
     * Traduce el título del medio al idioma actual de la aplicación.
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

        /** @var MaestroMedio $medioDL */
        $medioDL = $this->entityManager->getRepository(MaestroMedio::class)->find($id);

        // Carga del contenido original
        $medioDL->setLocale($defaultLocale);
        $this->entityManager->refresh($medioDL);
        $tituloDL = $medioDL->getTitulo();

        // Regreso al locale destino para persistir la traducción
        $medioDL->setLocale($targetLocale);
        $this->entityManager->refresh($medioDL);

        if (!empty($tituloDL)) {
            try {
                $translations = $this->translateService->translate(
                    $tituloDL,
                    $targetLocale,
                    $defaultLocale
                );

                $tituloTL = $translations[0] ?? '';

                // Preservar capitalización inicial con soporte multibyte
                if (mb_substr($tituloDL, 0, 1) === mb_strtoupper(mb_substr($tituloDL, 0, 1))) {
                    $tituloTL = $this->mb_ucfirst($tituloTL);
                }

                $object->setTitulo($tituloTL);
                $this->admin->update($object);

                $this->addFlash('sonata_flash_success', 'Medio traducido correctamente mediante API V3');
            } catch (ApiException $e) {
                $this->addFlash('sonata_flash_error', 'Error en Google Translate Service: ' . $e->getMessage());
            }
        }

        return new RedirectResponse($this->admin->generateUrl('list'));
    }

    /**
     * Renderiza la vista personalizada para la carga masiva de medios.
     * * @param Request $request
     * @return Response
     */
    public function cargaAction(Request $request): Response
    {
        $this->admin->checkAccess('create');

        $template = 'oweb/admin/reserva_unitmedio/carga.html.twig';
        $newObject = $this->admin->getNewInstance();

        return $this->renderWithExtraParams($template, [
            'object' => $newObject,
            'action' => 'carga',
            'objectId' => null
        ]);
    }

    /**
     * Procesa la carga de archivos vía AJAX, decodificando base64 a archivos temporales.
     * * @param Request $request
     * @return Response
     */
    public function ajaxcrearAction(Request $request): Response
    {
        if (!$this->isXmlHttpRequest($request)) {
            return $this->renderJson(['error' => 'El método no es válido'], Response::HTTP_METHOD_NOT_ALLOWED);
        }

        $this->admin->checkAccess('create');

        parse_str($request->getContent(), $parsedContent);
        if (!isset($parsedContent['json'])) {
            return $this->renderJson(['error' => 'No se ha podido convertir el requerimiento en variables'], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($parsedContent['json']);
        $filename = \sys_get_temp_dir() . '/' . mt_rand() . '_' . $data->name;

        // Limpieza del prefijo data:type;base64 para obtener el contenido puro
        $dataFileDec = base64_decode(
            preg_replace('#^data:[\w/]+;base64,#', '', $data->file)
        );

        if (!file_put_contents($filename, $dataFileDec)) {
            return $this->renderJson(['error' => 'No se ha podido escribir el archivo temporal ' . $filename], Response::HTTP_BAD_REQUEST);
        }

        // Se crea el objeto UploadedFile marcado como test para permitir el procesamiento de archivos temporales
        $fakeUpload = new UploadedFile($filename, $data->name, $data->type, null, true);

        $maestroMedio = new MaestroMedio();
        $maestroMedio->setArchivo($fakeUpload);
        $maestroMedio->setNombre(pathinfo($data->name, PATHINFO_FILENAME));
        $maestroMedio->setTitulo(pathinfo($data->name, PATHINFO_FILENAME));

        $this->entityManager->persist($maestroMedio);
        $this->entityManager->flush();

        $thumbRaw = @file_get_contents($maestroMedio->getInternalThumbPath());
        if ($thumbRaw === false) {
            return $this->renderJson(['error' => 'No se ha podido leer el archivo de miniatura.'], Response::HTTP_NOT_FOUND);
        }

        $tipoThumb = $maestroMedio->getTipoThumb();
        if (empty($tipoThumb)) {
            return $this->renderJson(['error' => 'Tipo de miniatura no definido.'], Response::HTTP_NOT_FOUND);
        }

        $renderDataType = ($tipoThumb === 'image') ? 'data:' . $data->type . ';base64,' : 'data:image/png;base64,';

        $result = [
            'file' => $renderDataType . base64_encode($thumbRaw),
            'type' => $data->type,
            'aspectRatio' => $maestroMedio->getAspectRatio(),
            'name' => $maestroMedio->getNombre()
        ];

        return $this->renderJson($result, Response::HTTP_OK);
    }

    /**
     * Helper para capitalizar la primera letra de un string con soporte para caracteres especiales.
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