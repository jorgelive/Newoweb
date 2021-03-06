<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use App\Service\CotizacionResumen;
use Doctrine\ORM\EntityManagerInterface;

class CotizacionCotizacionAdminController extends CRUDAdminController
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

    public function clonarAction(Request $request = null)
    {

        $object = $this->assertObjectExists($request, true);

        try {
            $newFechaInicio = new \DateTime($request->query->get('fechainicio'));
            $mensaje = 'Cotización clonada correctamente';
            $mensajeTyoe = 'success';
        } catch (\Exception $e) {
            $newFechaInicio = new \DateTime('today');
            $mensaje = 'Formato de fecha incorrecto, la cotización se ha clonado para la fecha de hoy';
            $mensajeTyoe = 'info';
        }

        $id = $object->getId();
        $em = $this->container->get('doctrine.orm.default_entity_manager');

        if (!$object) {
            throw $this->createNotFoundException(sprintf('Unable to find the object with id: %s', $id));
        }

        $this->admin->checkAccess('edit', $object);

        $newObject = clone $object;
        $newObject->setNombre($object->getNombre().' (Clone)');
        $newObject->setEstadocotizacion($em->getReference('App\Entity\CotizacionEstadocotizacion', 1));

        foreach ($newObject->getCotservicios() as $cotservicio):
            //en la primera iteracion coniderando que el orden es por fecha de inicio
            if(!isset($oldFechaInicio)){
                $oldFechaInicio = new \DateTime($cotservicio->getFechaHoraInicio()->format('Y-m-d'));
                $interval = $oldFechaInicio->diff($newFechaInicio);
            }
            $cotservicio->getFechaHoraInicio()->add($interval);
            $cotservicio->getFechaHoraFin()->add($interval);
            foreach ($cotservicio->getCotcomponentes() as $cotcomponente):
                $cotcomponente->getFechaHoraInicio()->add($interval);
                $cotcomponente->getFechaHoraFin()->add($interval);
                $cotcomponente->setEstadocotcomponente($em->getReference('App\Entity\CotizacionEstadocotcomponente', 1));
            endforeach;
        endforeach;

        $this->admin->create($newObject);
        $this->addFlash('sonata_flash_' . $mensajeTyoe, $mensaje);

        return new RedirectResponse($this->admin->generateUrl('list'));

    }

    public function showAction(Request $request = null):\Symfony\Component\HttpFoundation\Response
    {

        $object = $this->assertObjectExists($request, true);
        \assert(null !== $object);

        $this->checkParentChildAssociation($request, $object);

        $this->admin->checkAccess('show', $object);

        $preResponse = $this->preShow($request, $object);
        if (null !== $preResponse) {
            return $preResponse;
        }

        $this->admin->setSubject($object);

        $fields = $this->admin->getShow();

        $template = 'cotizacion_cotizacion_admin/show.html.twig';

        if($this->container->get('App\Service\CotizacionResumen')->setTl($request->get('tl'))->procesar($object->getId())){
            return $this->renderWithExtraParams($template,
                ['cotizacion' => $this->container->get('App\Service\CotizacionResumen')->getDatosCotizacion(),
                    'tabs' => $this->container->get('App\Service\CotizacionResumen')->getDatosTabs(),
                    'object' => $object,
                    'action' => 'show',
                    'elements' => $fields

                ], null);
        }else{
            $this->addFlash('sonata_flash_error', $this->container->get('App\Service\CotizacionResumen')->getMensaje());
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

    }

    function resumenAction(Request $request = null):\Symfony\Component\HttpFoundation\Response
    {

        $object = $this->assertObjectExists($request, true);
        \assert(null !== $object);

//todo eliminar el acceso sin token el 2022/07/05
        if($object->getId() >= 440){
            if($request->get('token') != $object->getToken()){
                $this->addFlash('sonata_flash_error', 'El código de autorización no coincide');
                return new RedirectResponse($this->admin->generateUrl('list'));
            }
        }
        if($object->getEstadocotizacion()->isOcultoResumen()){
                $this->addFlash('sonata_flash_error', 'El no se muestra en resumen, redirigido a "Mostrar"');
                return new RedirectResponse($this->admin->generateUrl('show', ['id' => $object->getId()]));
        }

        $this->checkParentChildAssociation($request, $object);

        //$this->admin->checkAccess('show', $object);

        $preResponse = $this->preShow($request, $object);
        if (null !== $preResponse) {
            return $preResponse;
        }

        $this->admin->setSubject($object);

        $fields = $this->admin->getShow();

        $template = 'cotizacion_cotizacion_admin/show.html.twig';

        if($this->container->get('App\Service\CotizacionResumen')->setTl($request->get('tl'))->procesar($object->getId())){
            return $this->renderWithExtraParams($template,
                ['cotizacion' => $this->container->get('App\Service\CotizacionResumen')->getDatosCotizacion(),
                    'tabs' => $this->container->get('App\Service\CotizacionResumen')->getDatosTabs(),
                    'object' => $object,
                    'action' => 'resumen',
                    'elements' => $fields,

                ], null);
        }else{
            $this->addFlash('sonata_flash_error', $this->container->get('App\Service\CotizacionResumen')->getMensaje());
            return new RedirectResponse($this->admin->generateUrl('list'));
        }
    }
}
