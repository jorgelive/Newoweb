<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use Google\Cloud\Translate\V2\TranslateClient;

class ServicioComponenteitemAdminController extends CRUDAdminController
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

        $componenteitemDL = $em->getRepository('App\Entity\Serviciocomponenteitem')->find($id);
        $componenteitemDL->setLocale($request->getDefaultLocale());
        $em->refresh($componenteitemDL);

        $tituloDL = $componenteitemDL->getTitulo();

        $componenteitemDL->setLocale($request->getLocale());
        $em->refresh($componenteitemDL);

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

        $existingObject = $this->admin->update($object);

        $this->addFlash('sonata_flash_success', 'Dia de itinerario traducido correctamente');

        return new RedirectResponse($this->admin->generateUrl('list'));

    }
}