<?php

namespace App\Oweb\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class FitDietaController extends CRUDController
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

        $template = 'oweb/admin/oweb/admin/fit_dieta/show.html.twig';

        return $this->renderWithExtraParams($template, [
            'action' => 'show',
            'object' => $object,
            'elements' => $fields,
        ]);
    }




}
