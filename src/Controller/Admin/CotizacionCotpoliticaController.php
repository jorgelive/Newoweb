<?php

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use Google\Cloud\Translate\V2\TranslateClient;

class CotizacionCotpoliticaController extends CRUDController
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

        $cotpoliticaDL = $this->entityManager->getRepository('App\Entity\Cotizacioncotpolitica')->find($id);
        $cotpoliticaDL->setLocale($request->getDefaultLocale());
        $this->entityManager->refresh($cotpoliticaDL);

        $contenidoDL = $cotpoliticaDL->getContenido();

        $cotpoliticaDL->setLocale($request->getLocale());
        $this->entityManager->refresh($cotpoliticaDL);

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

        $this->addFlash('sonata_flash_success', 'Política traducida correctamente');

        return new RedirectResponse($this->admin->generateUrl('list'));

    }
}