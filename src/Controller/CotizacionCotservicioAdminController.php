<?php

namespace App\Controller;


use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use App\Service\IcalGenerator;

class CotizacionCotservicioAdminController extends CRUDAdminController
{

    public static function getSubscribedServices(): array
    {
        return [
                'App\Service\IcalGenerator' => IcalGenerator::class,
                'doctrine.orm.default_entity_manager' => EntityManagerInterface::class
            ] + parent::getSubscribedServices();
    }


    /**
     *
     */
    public function icalAction(Request $request): Response
    {
        $this->assertObjectExists($request);
        $estado = 3; //3 Aceptado
        $em = $this->container->get('doctrine.orm.default_entity_manager');

        $qb = $em->createQueryBuilder()
            ->select('cs')
            ->from('App:CotizacionCotservicio', 'cs')
            ->innerJoin('cs.cotizacion', 'c')
            ->innerJoin('c.estadocotizacion', 'ec')
            ->where('ec.id = :estado')
            ->setParameter('estado', $estado)
            ->orderBy('cs.fechahorainicio', 'ASC')
        ;

        $cotServicios= $qb->getQuery()->getResult();

        $calendar = $this->container->get('App\Service\IcalGenerator')->setTimezone('America/Lima')->setProdid('-//OpenPeru//Cotservicio Calendar //ES')->createCalendar();

        foreach ($cotServicios as $cotServicio){

            $cotComponentes = $cotServicio->getCotComponentes();
            $decripcion = [];
            foreach ($cotComponentes as $cotComponente){
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
