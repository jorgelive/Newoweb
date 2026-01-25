<?php

namespace App\Oweb\Controller;

use App\Oweb\Entity\ReservaUnitcaracteristica;
use App\Oweb\Entity\ReservaUnitmedio;
use App\Service\GoogleTranslateService;
use Doctrine\ORM\EntityManagerInterface;
use Google\ApiCore\ApiException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controlador para la gestión de medios de unidades de reserva.
 * Implementa traducción automática mediante el servicio de abstracción de Google V3
 * y carga de archivos vía AJAX con asociación opcional de características.
 */
class ReservaUnitmedioController extends CRUDController
{
    private EntityManagerInterface $entityManager;
    private GoogleTranslateService $translateService;

    /**
     * @param EntityManagerInterface $entityManager Manejador de persistencia.
     * @param GoogleTranslateService $translateService Servicio de abstracción para traducción V3.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        GoogleTranslateService $translateService
    ) {
        $this->entityManager = $entityManager;
        $this->translateService = $translateService;
    }

    /**
     * Traduce el título del medio de la unidad al idioma de la petición actual.
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

        /** @var ReservaUnitmedio $unitmedioDL */
        $unitmedioDL = $this->entityManager->getRepository(ReservaUnitmedio::class)->find($id);

        // 1. Cargar el contenido original (Source)
        $unitmedioDL->setLocale($defaultLocale);
        $this->entityManager->refresh($unitmedioDL);
        $tituloDL = $unitmedioDL->getTitulo();

        // 2. Regresar al idioma de la petición para la persistencia
        $unitmedioDL->setLocale($targetLocale);
        $this->entityManager->refresh($unitmedioDL);

        if (!empty($tituloDL)) {
            try {
                // Traducción mediante el servicio de abstracción para cumplir con la nota mental: no usar vendors directamente.
                $translations = $this->translateService->translate($tituloDL, $targetLocale, $defaultLocale);
                $tituloTL = $translations[0] ?? '';

                // Preservar capitalización inicial con soporte multibyte
                if (mb_substr($tituloDL, 0, 1) === mb_strtoupper(mb_substr($tituloDL, 0, 1))) {
                    $tituloTL = $this->mb_ucfirst($tituloTL);
                }

                $object->setTitulo($tituloTL);
                $this->admin->update($object);

                $this->addFlash('sonata_flash_success', 'Medio de la unidad traducido correctamente mediante API V3');
            } catch (ApiException $e) {
                $this->addFlash('sonata_flash_error', 'Error en el servicio de traducción: ' . $e->getMessage());
            }
        }

        return new RedirectResponse($this->admin->generateUrl('list'));
    }

    /**
     * Renderiza la vista para la carga masiva de archivos.
     * * @param Request $request
     * @return Response
     */
    public function cargaAction(Request $request): Response
    {
        $this->admin->checkAccess('create');

        $template = 'oweb/admin/reserva_unitmedio/carga.html.twig';
        $newObject = $this->admin->getNewInstance();

        $caracteristicas = $this->entityManager
            ->getRepository(ReservaUnitcaracteristica::class)
            ->findBy([], ['id' => 'ASC']);

        return $this->renderWithExtraParams($template, [
            'object'          => $newObject,
            'action'          => 'carga',
            'objectId'        => null,
            'caracteristicas' => $caracteristicas,
        ]);
    }

    /**
     * Procesa la creación de medios mediante peticiones AJAX y decodificación de base64.
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
        $unitcaracteristicaId = $data->unitcaracteristica_id ?? null;

        $filename = \sys_get_temp_dir() . '/' . mt_rand() . '_' . $data->name;

        // Limpieza de prefijo data: URI para decodificación pura de base64
        $dataFileDec = base64_decode(
            preg_replace('#^data:[\w/]+;base64,#', '', $data->file)
        );

        if (!file_put_contents($filename, $dataFileDec)) {
            return $this->renderJson(['error' => 'No se ha podido escribir el archivo temporal ' . $filename], Response::HTTP_BAD_REQUEST);
        }

        $fakeUpload = new UploadedFile($filename, $data->name, $data->type, null, true);

        $unitMedio = new ReservaUnitmedio();
        $unitMedio->setArchivo($fakeUpload);
        $unitMedio->setNombre(pathinfo($data->name, PATHINFO_FILENAME));
        $unitMedio->setTitulo(pathinfo($data->name, PATHINFO_FILENAME));

        if ($unitcaracteristicaId) {
            $unitCaracteristica = $this->entityManager
                ->getRepository(ReservaUnitcaracteristica::class)
                ->find($unitcaracteristicaId);

            if ($unitCaracteristica) {
                $unitMedio->setUnitcaracteristica($unitCaracteristica);
            }
        }

        $this->entityManager->persist($unitMedio);
        $this->entityManager->flush();

        $thumbRaw = @file_get_contents($unitMedio->getInternalThumbPath());
        if ($thumbRaw === false) {
            return $this->renderJson(['error' => 'No se ha podido leer el archivo de miniatura.'], Response::HTTP_NOT_FOUND);
        }

        $tipoThumb = $unitMedio->getTipoThumb();
        if (empty($tipoThumb)) {
            return $this->renderJson(['error' => 'Tipo de miniatura no definido.'], Response::HTTP_NOT_FOUND);
        }

        $renderDataType = ($tipoThumb === 'image') ? 'data:' . $data->type . ';base64,' : 'data:image/png;base64,';

        $result = [
            'file' => $renderDataType . base64_encode($thumbRaw),
            'type' => $data->type,
            'aspectRatio' => $unitMedio->getAspectRatio(),
            'name' => $unitMedio->getNombre()
        ];

        return $this->renderJson($result, Response::HTTP_OK);
    }

    /**
     * Helper para capitalizar la primera letra con soporte UTF-8.
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