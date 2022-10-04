<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

class FitDietaAdminController extends CRUDAdminController
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

        if(!$object) {
            throw $this->createNotFoundException(sprintf('unable to find the object with id: %s', $id));
        }

        $this->admin->checkAccess('create', $object);

        $newObject = clone $object;
        $newObject->setNombre($object->getNombre().' (Clone)');
        $this->admin->create($newObject);

        $this->addFlash('sonata_flash_success', 'Dieta clonada correctamente');

        return new RedirectResponse($this->admin->generateUrl('list'));

    }

    public function resumenAction(Request $request): Response
    {
        $object = $this->assertObjectExists($request, true);
        \assert(null !== $object);

        //verificamos token
        if($request->get('token') != $object->getToken()){
            $this->addFlash('sonata_flash_error', 'El código de autorización no coincide');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        $this->checkParentChildAssociation($request, $object);

        //lo hago publico
        //$this->admin->checkAccess('show', $object);

        $preResponse = $this->preShow($request, $object);
        if (null !== $preResponse) {
            return $preResponse;
        }

        $this->admin->setSubject($object);

        $fields = $this->admin->getShow();

        $template = 'fit_dieta_admin/show.html.twig';

        return $this->renderWithExtraParams($template, [
            'action' => 'show',
            'object' => $object,
            'elements' => $fields,
        ]);
    }




}
