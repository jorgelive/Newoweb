<?php

namespace App\Controller\Admin;

use Google\Cloud\Translate\V2\TranslateClient;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

class ServicioTarifaController extends CRUDController
{

    private EntityManagerInterface $entityManager;

    function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

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

        $tarifaDL = $this->entityManager->getRepository('App\Entity\Serviciotarifa')->find($id);
        $tarifaDL->setLocale($request->getDefaultLocale());
        $this->entityManager->refresh($tarifaDL);

        $tituloDL = $tarifaDL->getTitulo();

        $tarifaDL->setLocale($request->getLocale());
        $this->entityManager->refresh($tarifaDL);

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

        $this->addFlash('sonata_flash_success', 'Tarifa traducida correctamente');

        return new RedirectResponse($this->admin->generateUrl('list'));

    }


}