<?php

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use Google\Cloud\Translate\V2\TranslateClient;

class ServicioNotaitinerariodiaController extends CRUDController
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

        $notaitinerariodiaDL = $this->entityManager->getRepository('App\Entity\Servicionotaitinerariodia')->find($id);
        $notaitinerariodiaDL->setLocale($request->getDefaultLocale());
        $this->entityManager->refresh($notaitinerariodiaDL);

        $contenidoDL = $notaitinerariodiaDL->getContenido();

        $notaitinerariodiaDL->setLocale($request->getLocale());
        $this->entityManager->refresh($notaitinerariodiaDL);

        $translate = new TranslateClient([
            'key' => $this->getParameter('google_translate_key')
        ]);

        if(!empty($contenidoDL)) {
            $contenidoTL = $translate->translate($contenidoDL, [
                'target' => $request->getLocale(),
                'source' => $request->getDefaultLocale()
            ]);
            $object->setContenido($contenidoTL['text']);
        }

        $existingObject = $this->admin->update($object);

        $this->addFlash('sonata_flash_success', 'Nota de dia de itinerario traducido correctamente');

        return new RedirectResponse($this->admin->generateUrl('list'));

    }
}