<?php

namespace App\Oweb\Controller;

use App\Oweb\Entity\ServicioProvidermedio;
use App\Service\GoogleTranslateService;
use Doctrine\ORM\EntityManagerInterface;
use Google\ApiCore\ApiException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controlador para la gestión de medios de proveedores de servicio.
 * Implementa la traducción automática V3 y la carga de archivos vía AJAX,
 * cumpliendo con el estándar de abstracción de servicios de terceros.
 */
class ServicioProvidermedioController extends CRUDController
{
    private EntityManagerInterface $entityManager;
    private GoogleTranslateService $translateService;

    /**
     * @param EntityManagerInterface $entityManager Manejador de persistencia de Doctrine.
     * @param GoogleTranslateService $translateService Servicio de abstracción para traducción (V3).
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        GoogleTranslateService $translateService
    ) {
        $this->entityManager = $entityManager;
        $this->translateService = $translateService;
    }

    /**
     * Traduce el título del medio del proveedor al idioma de la petición.
     * * @param Request $request
     * @return Response
     * @throws NotFoundHttpException Si el objeto no existe.
     */
    public function traducirAction(Request $request): Response
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

        /** @var ServicioProvidermedio $providermedioDL */
        $providermedioDL = $this->entityManager->getRepository(ServicioProvidermedio::class)->find($id);

        // 1. Cargar contenido original del idioma por defecto
        $providermedioDL->setLocale($defaultLocale);
        $this->entityManager->refresh($providermedioDL);
        $tituloDL = $providermedioDL->getTitulo();

        // 2. Cambiar al locale destino para persistir la traducción
        $providermedioDL->setLocale($targetLocale);
        $this->entityManager->refresh($providermedioDL);

        if (!empty($tituloDL)) {
            try {
                // Traducción mediante el servicio de abstracción para cumplir con el estándar solicitado.
                $translations = $this->translateService->translate($tituloDL, $targetLocale, $defaultLocale);
                $tituloTL = $translations[0] ?? '';

                // Preservar capitalización inicial con soporte multibyte para evitar errores en caracteres especiales.
                if (mb_substr($tituloDL, 0, 1) === mb_strtoupper(mb_substr($tituloDL, 0, 1))) {
                    $tituloTL = $this->mb_ucfirst($tituloTL);
                }

                $object->setTitulo($tituloTL);
                $this->admin->update($object);

                $this->addFlash('sonata_flash_success', 'Medio del proveedor traducido correctamente mediante API V3');
            } catch (ApiException $e) {
                $this->addFlash('sonata_flash_error', 'Error en el servicio de traducción: ' . $e->getMessage());
            }
        }

        return new RedirectResponse($this->admin->generateUrl('list'));
    }

    /**
     * Renderiza la vista para la carga masiva de medios del proveedor.
     * * @param Request $request
     * @return Response
     */
    public function cargaAction(Request $request): Response
    {
        $this->admin->checkAccess('create');

        $template = 'oweb/admin/servicio_providermedio/carga.html.twig';
        $newObject = $this->admin->getNewInstance();

        return $this->renderWithExtraParams($template, [
            'object' => $newObject,
            'action' => 'carga',
            'objectId' => null
        ]);
    }

    /**
     * Procesa la creación de medios vía AJAX decodificando el contenido base64.
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

        // Limpieza de prefijos data URI para asegurar una decodificación base64 limpia.
        $dataFileDec = base64_decode(
            preg_replace('#^data:[\w/]+;base64,#', '', $data->file)
        );

        if (!file_put_contents($filename, $dataFileDec)) {
            return $this->renderJson(['error' => 'No se ha podido escribir el archivo temporal ' . $filename], Response::HTTP_BAD_REQUEST);
        }

        $fakeUpload = new UploadedFile($filename, $data->name, $data->type, null, true);

        $providerMedio = new ServicioProvidermedio();
        $providerMedio->setArchivo($fakeUpload);
        $providerMedio->setNombre(pathinfo($data->name, PATHINFO_FILENAME));
        $providerMedio->setTitulo(pathinfo($data->name, PATHINFO_FILENAME));

        $this->entityManager->persist($providerMedio);
        $this->entityManager->flush();

        $thumbRaw = @file_get_contents($providerMedio->getInternalThumbPath());
        if ($thumbRaw === false) {
            return $this->renderJson(['error' => 'No se ha podido leer el archivo de miniatura.'], Response::HTTP_NOT_FOUND);
        }

        $tipoThumb = $providerMedio->getTipoThumb();
        if (empty($tipoThumb)) {
            return $this->renderJson(['error' => 'Tipo de miniatura no definido.'], Response::HTTP_NOT_FOUND);
        }

        $renderDataType = ($tipoThumb === 'image') ? 'data:' . $data->type . ';base64,' : 'data:image/png;base64,';

        $result = [
            'file' => $renderDataType . base64_encode($thumbRaw),
            'type' => $data->type,
            'aspectRatio' => $providerMedio->getAspectRatio(),
            'name' => $providerMedio->getNombre()
        ];

        return $this->renderJson($result, Response::HTTP_OK);
    }

    /**
     * Capitaliza la primera letra de un string con soporte UTF-8 para evitar errores con caracteres latinos.
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