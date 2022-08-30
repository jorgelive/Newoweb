<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use Google\Cloud\Translate\V2\TranslateClient;

class ServicioNotaitinerariodiaAdminController extends CRUDAdminController
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

        $notaitinerariodiaDL = $em->getRepository('App\Entity\Servicionotaitinerariodia')->find($id);
        $notaitinerariodiaDL->setLocale($request->getDefaultLocale());
        $em->refresh($notaitinerariodiaDL);

        $tituloDL = $notaitinerariodiaDL->getTitulo();

        $notaitinerariodiaDL->setLocale($request->getLocale());
        $em->refresh($notaitinerariodiaDL);

        $translate = new TranslateClient([
            'key' => $this->getParameter('google_translate_key')
        ]);

        $tituloTL = $translate->translate($tituloDL, [
            'target' => $request->getLocale(),
            'source' => $request->getDefaultLocale()
        ]);

        $object->setTitulo($tituloTL['text']);

        $existingObject = $this->admin->update($object);

        $this->addFlash('sonata_flash_success', 'Nota de dia de itinerario traducido correctamente');

        return new RedirectResponse($this->admin->generateUrl('list'));

    }
}