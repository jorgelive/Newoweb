<?php

namespace App\Controller;

use App\Service\IcalGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

class ReservaUnitAdminController extends CRUDAdminController
{

    public static function getSubscribedServices(): array
    {
        return [
                'App\Service\IcalGenerator' => IcalGenerator::class,
                'doctrine.orm.default_entity_manager' => EntityManagerInterface::class
            ] + parent::getSubscribedServices();
    }

    public function icalAction(Request $request = null): Response
    {
        $ahora = new \DateTime('now');
        $object = $this->assertObjectExists($request, true);
        if (!$object) {
            throw $this->createNotFoundException('Unable to find the object processing the request');
        }
        $id = $object->getId();

        $em = $this->container->get('doctrine.orm.default_entity_manager');
        $qb = $em->createQueryBuilder()
            ->select('rr')
            ->from('App\Entity\ReservaReserva', 'rr')
            ->where('rr.unit = :unit')
            ->andWhere('rr.estado = :estado')
            ->andWhere('DATE(rr.fechahorafin) >= :fechahorafin')
            ->orderBy('rr.fechahorainicio', 'ASC')
            ->setParameter('unit', $id)
            ->setParameter('estado', 2)
            ->setParameter('fechahorafin', $ahora->format('Y-m-d'));
        ;

        $reservas = $qb->getQuery()->getResult();

        $calendar = $this->container->get('App\Service\IcalGenerator')->setTimezone('America/Lima')->setProdid('-//OpenPeru//Cotservicio Calendar //ES')->createCalendar();

        foreach ($reservas as $reserva){

            $fechainicio = new \DateTime($reserva->getFechahorainicio()->format('Y-m-d'));
            $fechafin = new \DateTime($reserva->getFechahorafin()->format('Y-m-d'));

            $tempEvent = $this->container->get('App\Service\IcalGenerator')
                ->createCalendarEvent()
                ->setStart($fechainicio)
                ->setEnd($fechafin)
                ->setSummary($reserva->getNombre())
                ->setDescription($reserva->getEnlace())
                ->setUid($reserva->getUid());
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
