<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use Google\Cloud\Translate\V2\TranslateClient;

class ReservaUnitcaracteristicaAdminController extends CRUDAdminController
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

        $this->admin->checkAccess('edit', $object);

        $em = $this->container->get('doctrine.orm.default_entity_manager');

        $unitcaracteristicaDL = $em->getRepository('App\Entity\ReservaUnitcaracteristica')->find($id);
        $unitcaracteristicaDL->setLocale($request->getDefaultLocale());
        $em->refresh($unitcaracteristicaDL);

        $contenidoDL = $unitcaracteristicaDL->getContenido();

        $unitcaracteristicaDL->setLocale($request->getLocale());
        $em->refresh($unitcaracteristicaDL);

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

        $this->addFlash('sonata_flash_success', 'La característica de la unidad traducida correctamente');

        return new RedirectResponse($this->admin->generateUrl('list'));

    }
}