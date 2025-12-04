<?php

namespace App\Controller\Admin;

use App\Entity\ReservaUnitmedio;
use App\Entity\ReservaUnitcaracteristica;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use Google\Cloud\Translate\V2\TranslateClient;
use Symfony\Component\HttpFoundation\Response;

class ReservaUnitmedioController extends CRUDController
{
    private EntityManagerInterface $entityManager;

    function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function traducirAction(Request $request): Response
    {
        $object = $this->assertObjectExists($request, true);
        $id = $object->getId();
        if ($request->getDefaultLocale() == $request->getLocale()) {
            $this->addFlash('sonata_flash_error', 'El idioma actual debe ser diferente al idioma por defecto de la aplicación');

            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        if (!$object) {
            throw $this->createNotFoundException(sprintf('unable to find the object with id: %s', $id));
        }

        $this->admin->checkAccess('edit', $object);

        $unitmedioDL = $this->entityManager->getRepository('App\Entity\ReservaUnitmedio')->find($id);
        $unitmedioDL->setLocale($request->getDefaultLocale());
        $this->entityManager->refresh($unitmedioDL);

        $tituloDL = $unitmedioDL->getTitulo();

        $unitmedioDL->setLocale($request->getLocale());
        $this->entityManager->refresh($unitmedioDL);

        $translate = new TranslateClient([
            'key' => $this->getParameter('google_translate_key')
        ]);

        if (!empty($tituloDL)) {
            $tituloTL = $translate->translate($tituloDL, [
                'target' => $request->getLocale(),
                'source' => $request->getDefaultLocale()
            ]);
            if (substr($tituloDL, 0, 1) === strtoupper(substr($tituloDL, 0, 1))) {
                $tituloTL['text'] = ucfirst($tituloTL['text']);
            }
            $object->setTitulo($tituloTL['text']);
        }

        $existingObject = $this->admin->update($object);

        $this->addFlash('sonata_flash_success', 'Medio de la unidad traducido correctamente');

        return new RedirectResponse($this->admin->generateUrl('list'));
    }

    public function cargaAction(Request $request): Response
    {
        $this->admin->checkAccess('create');

        $template = 'admin/reserva_unitmedio/carga.html.twig';

        $newObject = $this->admin->getNewInstance();

        // lista para el dropdown (opcional en la vista)
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

        // puede venir null/"" => es opcional
        $unitcaracteristicaId = $data->unitcaracteristica_id ?? null;

        $filename = \sys_get_temp_dir() . '/' . mt_rand() . '_' . $data->name;

        $dataFileDec = base64_decode(
            str_replace('data:' . $data->type . ';base64,', '', $data->file)
        );
        if (!file_put_contents($filename, $dataFileDec)) {
            return $this->renderJson(['error' => 'No se ha podido escribir el archivo temporal ' . $filename], Response::HTTP_BAD_REQUEST);
        }

        $fakeUpload = new UploadedFile($filename, $data->name, $data->type, null, true); // test por el ajax

        $unitMedio = new ReservaUnitmedio();
        $unitMedio->setArchivo($fakeUpload);
        $unitMedio->setNombre(pathinfo($data->name, PATHINFO_FILENAME));
        $unitMedio->setTitulo(pathinfo($data->name, PATHINFO_FILENAME));

        // si viene un id válido, se asocia; si no, queda null (opcional)
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

        $thumbRaw = file_get_contents($unitMedio->getInternalThumbPath());
        if ($thumbRaw == false) {
            return $this->renderJson(['error' => 'No se ha podido leer el archivo de miniatura.'], Response::HTTP_NOT_FOUND);
        }

        $tipoThumb = $unitMedio->getTipoThumb();

        $renderDataType = '';
        if (empty($tipoThumb)) {
            return $this->renderJson(null, Response::HTTP_NOT_FOUND);
        } elseif ($tipoThumb == 'image') {
            $renderDataType = 'data:' . $data->type . ';base64,';
        } elseif ($tipoThumb == 'icon') {
            $renderDataType = 'data:image/png;base64,';
        }

        $result['file'] = $renderDataType . base64_encode($thumbRaw);
        $result['type'] = $data->type;
        $result['aspectRatio'] = $unitMedio->getAspectRatio();
        $result['name'] = $unitMedio->getNombre();

        return $this->renderJson($result, Response::HTTP_OK);
    }
}