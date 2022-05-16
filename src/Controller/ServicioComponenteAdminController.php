<?php

namespace App\Controller;


use Sonata\AdminBundle\Controller\CRUDController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;

class ServicioComponenteAdminController extends CRUDController
{

    public static function getSubscribedServices(): array
    {
        return [
                'doctrine.orm.default_entity_manager' => EntityManagerInterface::class
            ] + parent::getSubscribedServices();
    }

    public function clonarAction($id = null, Request $request = null)
    {

        $id = $request->get($this->admin->getIdParameter());

        $object = $this->admin->getObject($id);

        $em = $this->container->get('doctrine.orm.default_entity_manager');

        if (!$object) {
            throw $this->createNotFoundException(sprintf('unable to find the object with id: %s', $id));
        }

        $this->admin->checkAccess('edit', $object);

        $newObject = clone $object;

        $newObject->setNombre($object->getNombre() . ' (Clone)');

        $this->admin->create($newObject);

        $this->addFlash('sonata_flash_success', 'Componente clonado correctamente');

        return new RedirectResponse($this->admin->generateUrl('list'));

    }
}