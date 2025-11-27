<?php

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use Google\Cloud\Translate\V2\TranslateClient;

class ServicioItinerariodiaController extends CRUDController
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

        $itinerariodiaDL = $this->entityManager->getRepository('App\Entity\Servicioitinerariodia')->find($id);
        $itinerariodiaDL->setLocale($request->getDefaultLocale());
        $this->entityManager->refresh($itinerariodiaDL);

        $tituloDL = $itinerariodiaDL->getTitulo();
        $contenidoDL = $itinerariodiaDL->getContenido();

        $itinerariodiaDL->setLocale($request->getLocale());
        $this->entityManager->refresh($itinerariodiaDL);

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

        if(!empty($contenidoDL)) {
            $contenidoTL = $translate->translate($contenidoDL, [
                'target' => $request->getLocale(),
                'source' => $request->getDefaultLocale()
            ]);
            $object->setContenido($contenidoTL['text']);
        }

        $existingObject = $this->admin->update($object);

        $this->addFlash('sonata_flash_success', 'Dia de itinerario traducido correctamente');

        return new RedirectResponse($this->admin->generateUrl('list'));

    }
}