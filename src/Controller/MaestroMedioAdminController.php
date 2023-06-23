<?php

namespace App\Controller;

use App\Entity\MaestroMedio;
use App\Entity\ReservaUnitmedio;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use Google\Cloud\Translate\V2\TranslateClient;
use Symfony\Component\HttpFoundation\Response;

class MaestroMedioAdminController extends CRUDAdminController
{

    private EntityManagerInterface $entityManager;

    function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function traducirAction(Request $request)
    {

        $object = $this->assertObjectExists($request, true);
        $id = $object->getId();
        if($request->getDefaultLocale() == $request->getLocale()){
            $this->addFlash('sonata_flash_error', 'El idioma actual debe ser diferente al idioma por defecto de la aplicación');

            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        if(!$object) {
            throw $this->createNotFoundException(sprintf('unable to find the object with id: %s', $id));
        }

        $this->admin->checkAccess('edit', $object);

        $medioDL = $this->entityManager->getRepository('App\Entity\MaestroMedio')->find($id);
        $medioDL->setLocale($request->getDefaultLocale());
        $this->entityManager->refresh($medioDL);

        $tituloDL = $medioDL->getTitulo();

        $medioDL->setLocale($request->getLocale());
        $this->entityManager->refresh($medioDL);

        $translate = new TranslateClient([
            'key' => $this->getParameter('google_translate_key')
        ]);

        if(!empty($tituloDL)) {
            $tituloTL = $translate->translate($tituloDL, [
                'target' => $request->getLocale(),
                'source' => $request->getDefaultLocale()
            ]);
            if(substr($tituloDL, 0, 1) === strtoupper(substr($tituloDL, 0, 1))){
                $tituloTL['text'] = ucfirst($tituloTL['text']);
            }
            $object->setTitulo($tituloTL['text']);
        }

        $existingObject = $this->admin->update($object);

        $this->addFlash('sonata_flash_success', 'Medio traducido correctamente');

        return new RedirectResponse($this->admin->generateUrl('list'));

    }

    public function cargaAction(Request $request): Response
    {
        $this->admin->checkAccess('create');

        //$template = $this->templateRegistry->getTemplate('show'); es privado en la clase padre
        $template = 'reserva_unitmedio_admin/carga.html.twig';

        $newObject = $this->admin->getNewInstance();

        return $this->renderWithExtraParams($template,
            [
                'object' => $newObject,
                'action' => 'carga',
                'objectId' => null
                //'elements' => $fields,
            ]);
    }

    public function ajaxcrearAction(Request $request): Response
    {
        if(!$this->isXmlHttpRequest($request)){
            return $this->renderJson(['error' => 'El método no es válido'], Response::HTTP_METHOD_NOT_ALLOWED);
        };
        $this->admin->checkAccess('create');
        //decodificamos el contenido crudo

        parse_str($request->getContent(), $parsedContent);
        if(!isset($parsedContent['json'])){
            return $this->renderJson(['error' => 'No se ha podido convertir el requerimiento en variables'], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($parsedContent['json']);
        $filename = \sys_get_temp_dir() . '/' . mt_rand() . '_' . $data->name;

        $dataFileDec = base64_decode(
            str_replace('data:' . $data->type .';base64,', '', $data->file)
        );
        if(!file_put_contents($filename, $dataFileDec)){
            return $this->renderJson(['error' => 'No se ha podido escribir el archivo temporal ' . $filename], Response::HTTP_BAD_REQUEST);
        }
        $fakeUpload = new UploadedFile($filename, $data->name, $data->type, null, true); //test por el ajax

        $maestroMedio = new MaestroMedio();
        $maestroMedio->setArchivo($fakeUpload);
        $maestroMedio->setNombre(pathinfo($data->name, PATHINFO_FILENAME));
        $maestroMedio->setTitulo(pathinfo($data->name, PATHINFO_FILENAME));

        // tell Doctrine you want to (eventually) save the Product (no queries yet)

        $this->entityManager->persist($maestroMedio);

        // actually executes the queries (i.e. the INSERT query)
        $this->entityManager->flush();

        $thumbRaw = file_get_contents($maestroMedio->getInternalThumbPath());
        if($thumbRaw == false){
            return $this->renderJson(['error' => 'No se ha podido leer el archivo de miniatura.'], Response::HTTP_NOT_FOUND);
        }

        $tipoThumb = $maestroMedio->getTipoThumb();

        $renderDataType = '';
        if(empty($tipoThumb)){
            return $this->renderJson(null, Response::HTTP_NOT_FOUND);
        }elseif ($tipoThumb == 'image'){
            $renderDataType = 'data:' . $data->type .';base64,';
        }elseif ($tipoThumb == 'icon'){
            $renderDataType = 'data:image/png;base64,';
        }

        $result['file'] = $renderDataType . base64_encode($thumbRaw);
        $result['type'] = $data->type;
        $result['aspectRatio'] = $maestroMedio->getAspectRatio();
        $result['name'] = $maestroMedio->getNombre();

        return $this->renderJson($result, Response::HTTP_OK);

    }
}