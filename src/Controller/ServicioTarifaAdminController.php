<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;

class ServicioTarifaAdminController extends CRUDAdminController
{

    public static function getSubscribedServices(): array
    {
        return [
                'doctrine.orm.default_entity_manager' => EntityManagerInterface::class
            ] + parent::getSubscribedServices();
    }

    public function clonarAction(Request $request = null)
    {

        $object = $this->assertObjectExists($request, true);
        $id = $object->getId();

        $em = $this->container->get('doctrine.orm.default_entity_manager');

        if (!$object) {
            throw $this->createNotFoundException(sprintf('unable to find the object with id: %s', $id));
        }

        $this->admin->checkAccess('edit', $object);

        $newObject = clone $object;
        $newObject->setNombre($object->getNombre() . ' (Clone)');
        $this->admin->create($newObject);

        $this->addFlash('sonata_flash_success', 'Tarifa clonada correctamente');

        return new RedirectResponse($this->admin->generateUrl('list'));

    }
}