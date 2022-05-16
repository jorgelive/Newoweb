<?php

namespace App\Controller;


use Sonata\AdminBundle\Controller\CRUDController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use App\Service\CotizacionResumen;
use Doctrine\ORM\EntityManagerInterface;

class CotizacionCotizacionAdminController extends CRUDController
{

    var $clasificacionTarifas = [];
    var $resumenClasificado = [];
    var $cotizacionResumen;

    public static function getSubscribedServices(): array
    {
        return [
                'doctrine.orm.default_entity_manager' => EntityManagerInterface::class,
                'App\Service\CotizacionResumen' => CotizacionResumen::class,
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

        $newObject->setNombre($object->getNombre().' (Clone)');

        $newObject->setEstadocotizacion($em->getReference('App\Entity\CotizacionEstadocotizacion', 1));

        foreach ($newObject->getCotservicios() as $cotservicio):
            foreach ($cotservicio->getCotcomponentes() as $cotcomponente):
                $cotcomponente->setEstadocotcomponente($em->getReference('App\Entity\CotizacionEstadocotcomponente', 1));
            endforeach;
        endforeach;

        $this->admin->create($newObject);

        $this->addFlash('sonata_flash_success', 'Cotización clonada correctamente');

        return new RedirectResponse($this->admin->generateUrl('list'));

    }

    public function showAction($id = null, Request $request = null):\Symfony\Component\HttpFoundation\Response
    {

        $id = $request->get($this->admin->getIdParameter());

        $object = $this->admin->getObject($id);

        if (!$object) {
            throw $this->createNotFoundException(sprintf('unable to find the object with id: %s', $id));
        }

        $this->admin->checkAccess('show', $object);

        $preResponse = $this->preShow($request, $object);
        if (null !== $preResponse) {
            return $preResponse;
        }

        $this->admin->setSubject($object);

        if($this->container->get('App\Service\CotizacionResumen')->setTl($request->get('tl'))->procesar($object->getId())){
            return $this->renderWithExtraParams('cotizacion_cotizacion_admin/show.html.twig',
                ['cotizacion' => $this->container->get('App\Service\CotizacionResumen')->getDatosCotizacion(),
                    'tabs' => $this->container->get('App\Service\CotizacionResumen')->getDatosTabs(),
                    'object' => $object,
                    'action' => 'show',
                    'elements' => $this->admin->getShow()

                ], null);
        }else{
            $this->addFlash('sonata_flash_error', $this->container->get('App\Service\CotizacionResumen')->getMensaje());
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

    }

    function resumenAction($id = null, Request $request = null)
    {

        //$request = $this->getRequest();
        $id = $request->get($this->admin->getIdParameter());

        $object = $this->admin->getObject($id);

        if (!$object) {
            throw $this->createNotFoundException(sprintf('unable to find the object with id: %s', $id));
        }

        //$this->admin->checkAccess('show', $object);

        $preResponse = $this->preShow($request, $object);
        if (null !== $preResponse) {
            return $preResponse;
        }

        $this->admin->setSubject($object);

        if($this->container->get('App\Service\CotizacionResumen')->setTl($request->get('tl'))->procesar($object->getId())){
            return $this->renderWithExtraParams('cotizacion_cotizacion_admin/resumen.html.twig',
                ['cotizacion' => $this->container->get('App\Service\CotizacionResumen')->getDatosCotizacion(),
                    'tabs' => $this->container->get('App\Service\CotizacionResumen')->getDatosTabs(),
                    'object' => $object,
                    'action' => 'resumen',
                    'elements' => $this->admin->getShow(),

                ], null);
        }else{
            $this->addFlash('sonata_flash_error', $this->container->get('App\Service\CotizacionResumen')->getMensaje());
            return new RedirectResponse($this->admin->generateUrl('list'));
        }
    }
}
