<?php

namespace App\Controller;


use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use App\Service\IcalGenerator;


class CotizacionCotservicioAdminController extends CRUDAdminController
{

    private EntityManagerInterface $entityManager;

    public static function getSubscribedServices(): array
    {
        return [
                'App\Service\IcalGenerator' => IcalGenerator::class,
                'doctrine.orm.default_entity_manager' => EntityManagerInterface::class
            ] + parent::getSubscribedServices();
    }

    public function clonarAction(Request $request): Response
    {
        $object = $this->assertObjectExists($request, true);

        $this->admin->checkAccess('create', $object);
        $this->entityManager = $this->container->get('doctrine.orm.default_entity_manager');

        if(!empty($request->query->get('cotizacion_id'))){

            $cotizacion = $this->entityManager
                ->getRepository('App\Entity\CotizacionCotizacion')
                ->find($request->query->get('cotizacion_id'));

            if (!empty($cotizacion)){
                $this->addFlash('sonata_flash_success', 'Se ha insertado el servicio a la cotización: ' . sprintf("OPC%05s", $request->query->get('cotizacion_id')));
            } else {
                $this->addFlash('sonata_flash_error', 'File incorrecto, no se ha clonado el objeto');
                return new RedirectResponse($request->headers->get('referer'));
            }
        }else{
            $this->addFlash('sonata_flash_info', 'Se ha clonado el servicio dentro de la misma cotización');

        }

        if(!empty($request->query->get('fechainicio'))){
            try {
                $newFechaInicio = new \DateTime($request->query->get('fechainicio'));
                $this->addFlash('sonata_flash_success', 'Servicio clonado correctamente, se ha cambiado la fecha de inicio de los servicios');

            } catch (\Exception $e) {
                $newFechaInicio = new \DateTime('today');
                $this->addFlash('sonata_flash_info', 'Formato de fecha incorrecto, la cotización se ha clonado para la fecha de hoy');
            }
        }else{
            $this->addFlash('sonata_flash_success', 'Cotización clonada correctamente, se ha mantenido la fecha de los servicios');
        }

        $this->admin->checkAccess('create', $object);

        $newObject = clone $object;

        if(isset($cotizacion) && !empty($cotizacion)){
            $newObject->setCotizacion($cotizacion);
        }

        $oldFechaInicio = new \DateTime($object->getFechaHoraInicio()->format('Y-m-d'));

        if(isset($newFechaInicio)){
            $interval = $oldFechaInicio->diff($newFechaInicio);
            $object->getFechaHoraInicio()->add($interval);
            $object->getFechaHoraFin()->add($interval);
        }
        foreach($object->getCotcomponentes() as $cotcomponente):
            if(isset($newFechaInicio) && isset($interval)) {
                $cotcomponente->getFechaHoraInicio()->add($interval);
                $cotcomponente->getFechaHoraFin()->add($interval);
            }
            $cotcomponente->setEstadocotcomponente($this->entityManager->getReference('App\Entity\CotizacionEstadocotcomponente', 1));
        endforeach;

        $this->admin->create($newObject);

        return new RedirectResponse($this->generateUrl('admin_app_cotizacioncotizacion_edit', ['id' => $cotizacion->getId()]));
    }

    public function icalAction(Request $request): Response
    {
        //$object = $this->assertObjectExists($request, true);
        //\assert(null !== $object);

        $estado = 3; //3 Aceptado
        $this->entityManager = $this->container->get('doctrine.orm.default_entity_manager');

        $qb = $this->entityManager->createQueryBuilder()
            ->select('cs')
            ->from('App\Entity\CotizacionCotservicio', 'cs')
            ->innerJoin('cs.cotizacion', 'c')
            ->innerJoin('c.estadocotizacion', 'ec')
            ->where('ec.id = :estado')
            ->setParameter('estado', $estado)
            ->orderBy('cs.fechahorainicio', 'ASC')
        ;

        $cotServicios = $qb->getQuery()->getResult();

        $calendar = $this->container->get('App\Service\IcalGenerator')->setTimezone('America/Lima')->setProdid('-//OpenPeru//Cotservicio Calendar //ES')->createCalendar();

        foreach($cotServicios as $cotServicio){

            $cotComponentes = $cotServicio->getCotComponentes();
            $decripcion = [];
            foreach($cotComponentes as $cotComponente){
                $decripcion[] = $cotComponente->getEstadocotcomponente()->getNombre() . ' / ' . $cotComponente->getFechahorainicio()->format('h:i d-m-Y') . ' ' . $cotComponente->getComponente()->getNombre();
            }

            $tempEvent = $this->container->get('App\Service\IcalGenerator')
                ->createCalendarEvent()
                ->setStart($cotServicio->getFechahorainicio())
                ->setEnd($cotServicio->getFechahorafin())
                ->setSummary($cotServicio->getResumen())
                ->setDescription(implode(' \n ', $decripcion))
                ->setUid(sprintf("cs%010s", $cotServicio->getId()) . '_' . sprintf("c%010s", $cotServicio->getCotizacion()->getId()) . '@openperu.pe');
            $calendar->addEvent($tempEvent);
        }
        $status = Response::HTTP_OK;
        return $this->makeIcalResponse($calendar, $status);
    }

    function makeIcalResponse($calendar, $status): Response
    {
        $mimeType = $calendar->getContentType();
        $filename = $calendar->getFilename();

        $response = new Response();
        $response->headers->set('Content-Type', sprintf('%s; charset=utf-8', $mimeType));
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s', $filename));
        $response->setContent($calendar->export());
        $response->setStatusCode($status);
        return $response;
    }

}
