<?php

namespace App\Controller;

use App\Entity\ServicioProvidermedio;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use Google\Cloud\Translate\V2\TranslateClient;
use Symfony\Component\HttpFoundation\Response;

class ServicioProvidermedioAdminController extends CRUDAdminController
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
        if($request->getDefaultLocale() == $request->getLocale()){
            $this->addFlash('sonata_flash_error', 'El idioma actual debe ser diferente al idioma por defecto de la aplicación');

            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        if(!$object) {
            throw $this->createNotFoundException(sprintf('unable to find the object with id: %s', $id));
        }

        $this->admin->checkAccess('edit', $object);

        $providermedioDL = $this->entityManager->getRepository('App\Entity\ServicioProvidermedio')->find($id);
        $providermedioDL->setLocale($request->getDefaultLocale());
        $this->entityManager->refresh($providermedioDL);

        $tituloDL = $providermedioDL->getTitulo();

        $providermedioDL->setLocale($request->getLocale());
        $this->entityManager->refresh($providermedioDL);

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

        $this->addFlash('sonata_flash_success', 'Medio del proveedor traducido correctamente');

        return new RedirectResponse($this->admin->generateUrl('list'));

    }

    public function cargaAction(Request $request): Response
    {
        $this->admin->checkAccess('create');

        //$template = $this->templateRegistry->getTemplate('show'); es privado en la clase padre
        $template = 'servicio_providermedio_admin/carga.html.twig';

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

        $providerMedio = new ServicioProvidermedio();
        $providerMedio->setArchivo($fakeUpload);
        $providerMedio->setNombre(pathinfo($data->name, PATHINFO_FILENAME));
        $providerMedio->setTitulo(pathinfo($data->name, PATHINFO_FILENAME));

        $this->entityManager->persist($providerMedio);

        $this->entityManager->flush();

        $thumbRaw = file_get_contents($providerMedio->getInternalThumbPath());
        if($thumbRaw == false){
            return $this->renderJson(['error' => 'No se ha podido leer el archivo de miniatura.'], Response::HTTP_NOT_FOUND);
        }

        $tipoThumb = $providerMedio->getTipoThumb();

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
        $result['aspectRatio'] = $providerMedio->getAspectRatio();
        $result['name'] = $providerMedio->getNombre();

        return $this->renderJson($result, Response::HTTP_OK);
    }
}