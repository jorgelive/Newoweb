<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

class ServicioComponenteAdminController extends CRUDAdminController
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

        $this->addFlash('sonata_flash_success', 'Componente clonado correctamente');

        return new RedirectResponse($this->admin->generateUrl('list'));

    }
}