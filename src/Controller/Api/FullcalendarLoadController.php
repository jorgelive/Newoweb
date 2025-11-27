<?php

namespace App\Controller\Api;

use App\Service\FullcalendarEventsfinder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/fullcalendar/load')]
class FullcalendarLoadController extends AbstractController
{
    public static function getSubscribedServices(): array
    {
        return [
                'App\Service\FullcalendarEventsfinder' => FullcalendarEventsfinder::class
            ] + parent::getSubscribedServices();
    }

    private $manager;

    #[Route('/event/{calendar}', name: 'app_fullcalendar_load_event')]
    function eventAction(Request $request, $calendar): Response
    {

        $data = [];
        $data['from'] = new \DateTime($request->get('start'));
        $data['to'] = new \DateTime($request->get('end'));

        $eventsfinder = $this->container->get('App\Service\FullcalendarEventsfinder');
        $eventsfinder->setCalendar($calendar);

        $events = $eventsfinder->getEvents($data);

        $jsonContent = $eventsfinder->serialize($events);

        $response = new Response();
        $response->headers->set('Content-Type', 'application/json');
        $response->setContent($jsonContent);
        $response->setStatusCode(Response::HTTP_OK);
        return $response;
    }


    #[Route('/resource/{calendar}', name: 'app_fullcalendar_load_resource')]
    function resourceAction(Request $request, $calendar): Response
    {

        $data = [];
        $data['from'] = new \DateTime($request->get('start'));
        $data['to'] = new \DateTime($request->get('end'));

        $eventsfinder = $this->container->get('App\Service\FullcalendarEventsfinder');
        $eventsfinder->setCalendar($calendar);

        $events = $eventsfinder->getEvents($data);

        $jsonContent = $eventsfinder->serializeResources($events);

        $response = new Response();
        $response->headers->set('Content-Type', 'application/json');
        $response->setContent($jsonContent);
        $response->setStatusCode(Response::HTTP_OK);

        return $response;
    }


}