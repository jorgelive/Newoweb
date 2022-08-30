<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use Google\Cloud\Translate\V2\TranslateClient;

class ServicioItinerariodiaAdminController extends CRUDAdminController
{

    public static function getSubscribedServices(): array
    {
        return [
                'doctrine.orm.default_entity_manager' => EntityManagerInterface::class
            ] + parent::getSubscribedServices();
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

        $em = $this->container->get('doctrine.orm.default_entity_manager');

        $itinerariodiaDL = $em->getRepository('App\Entity\Servicioitinerariodia')->find($id);
        $itinerariodiaDL->setLocale($request->getDefaultLocale());
        $em->refresh($itinerariodiaDL);

        $tituloDL = $itinerariodiaDL->getTitulo();
        $contenidoDL = $itinerariodiaDL->getContenido();

        $itinerariodiaDL->setLocale($request->getLocale());
        $em->refresh($itinerariodiaDL);

        $translate = new TranslateClient([
            'key' => $this->getParameter('google_translate_key')
        ]);

        $tituloTL = $translate->translate($tituloDL, [
            'target' => $request->getLocale(),
            'source' => $request->getDefaultLocale()
        ]);

        $contenidoTL = $translate->translate($contenidoDL, [
            'target' => $request->getLocale(),
            'source' => $request->getDefaultLocale()
        ]);

        $object->setTitulo($tituloTL['text']);
        $object->setContenido($contenidoTL['text']);

        $existingObject = $this->admin->update($object);

        $this->addFlash('sonata_flash_success', 'Dia de itinerario traducido correctamente');

        return new RedirectResponse($this->admin->generateUrl('list'));

    }
}