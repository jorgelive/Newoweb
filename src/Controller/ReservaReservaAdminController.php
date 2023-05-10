<?php

namespace App\Controller;

use App\Service\IcalGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

class ReservaReservaAdminController extends CRUDAdminController
{

    public static function getSubscribedServices(): array
    {
        return [
                'App\Service\IcalGenerator' => IcalGenerator::class,
                'doctrine.orm.default_entity_manager' => EntityManagerInterface::class
            ] + parent::getSubscribedServices();
    }

    public function resumenAction(Request $request = null): Response | RedirectResponse
    {
        $object = $this->assertObjectExists($request, true);
        \assert(null !== $object);

        //verificamos token
        if($request->get('token') != $object->getToken()){
            $this->addFlash('sonata_flash_error', 'El código de autorización no coincide');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        $this->checkParentChildAssociation($request, $object);

        //$this->admin->checkAccess('show', $object);

        $preResponse = $this->preShow($request, $object);
        if(null !== $preResponse) {
            return $preResponse;
        }

        $this->admin->setSubject($object);

        $fields = $this->admin->getShow();

        //$template = $this->templateRegistry->getTemplate('show'); es privado en la clase padre
        $template = 'reserva_reserva_admin/show.html.twig';

        return $this->renderWithExtraParams($template,
            [
                'object' => $object,
                'action' => 'resumen',
                'elements' => $fields,
            ]);

    }

    public function clonarAction(Request $request = null)
    {
        $object = $this->assertObjectExists($request, true);
        $id = $object->getId();

        $em = $this->container->get('doctrine.orm.default_entity_manager');

        if(!$object) {
            throw $this->createNotFoundException(sprintf('unable to find the object with id: %s', $id));
        }

        $this->admin->checkAccess('create', $object);

        $newObject = clone $object;

        $newObject->setUid('cl-' . $object->getUid());
        $newObject->setChanel($em->getReference('App\Entity\ReservaChanel', 1));
        $newObject->setEstado($em->getReference('App\Entity\ReservaEstado', 2));

        $newObject->setNombre($object->getNombre() . ' (Clone)');
        $this->admin->create($newObject);

        $this->addFlash('sonata_flash_success', 'Reserva clonada correctamente');

        return new RedirectResponse($this->admin->generateUrl('list'));

    }

    public function extenderAction(Request $request = null)
    {
        $object = $this->assertObjectExists($request, true);
        $id = $object->getId();

        $em = $this->container->get('doctrine.orm.default_entity_manager');

        if(!$object) {
            throw $this->createNotFoundException(sprintf('unable to find the object with id: %s', $id));
        }

        $this->admin->checkAccess('create', $object);

        $newObject = clone $object;

        $nuevaFechaInicial = clone $object->getFechahorafin();
        //$nuevaFechaInicial->add(new \DateInterval('P1D'));
        $nuevaFechaFinal = clone $object->getFechahorafin();
        $nuevaFechaFinal->add(new \DateInterval('P1D'));

        $newObject->setFechahorainicio($nuevaFechaInicial);
        $newObject->setUid('ad-' . $object->getUid());
        $newObject->setChanel($em->getReference('App\Entity\ReservaChanel', 1));
        $newObject->setEstado($em->getReference('App\Entity\ReservaEstado', 2));
        $newObject->setFechahorafin($nuevaFechaFinal);
        $newObject->setNombre($object->getNombre() . ' (Adicional)');
        $this->admin->create($newObject);

        $this->addFlash('sonata_flash_success', 'Reserva extendida correctamente');

        return new RedirectResponse($this->admin->generateUrl('list'));

    }

    public function icalAction(Request $request = null): Response
    {
        $ahora = new \DateTime('now');

        $em = $this->container->get('doctrine.orm.default_entity_manager');
        $qb = $em->createQueryBuilder()
            ->select('rr')
            ->from('App\Entity\ReservaReserva', 'rr')
            ->where('rr.estado = :estado')
            ->andWhere('DATE(rr.fechahorafin) >= :fechahorafin')
            ->orderBy('rr.fechahorainicio', 'ASC')
            ->setParameter('estado', 2)
            ->setParameter('fechahorafin', $ahora->sub(new \DateInterval('P7D'))->format('Y-m-d'));
        ;

        $reservas = $qb->getQuery()->getResult();

        $calendar = $this->container->get('App\Service\IcalGenerator')->setTimezone('America/Lima')->setProdid('-//OpenPeru//Cotservicio Calendar //ES')->createCalendar();

        foreach($reservas as $reserva){

            $fechainicioMasUno = new \DateTime($reserva->getFechahorainicio()->format('Y-m-d H:i') . '+1 hour');
            $fechafinMasUno = new \DateTime($reserva->getFechahorafin()->format('Y-m-d H:i') . '+1 hour');

            $tempEvent = $this->container->get('App\Service\IcalGenerator')
                ->createCalendarEvent()
                ->setStart($reserva->getFechahorainicio())
                ->setEnd($fechainicioMasUno)
                ->setSummary(sprintf('Check In: %s %s %s', $reserva->getNombre(),  $reserva->getUnit()->getNombre(), $reserva->getUnit()->getEstablecimiento()->getNombre()))
                ->setDescription($reserva->getEnlace())
                ->setUid('i-' . $reserva->getUid());
            $calendar->addEvent($tempEvent);

            $tempEvent = $this->container->get('App\Service\IcalGenerator')
                ->createCalendarEvent()
                ->setStart($reserva->getFechahorafin())
                ->setEnd($fechafinMasUno)
                ->setSummary(sprintf('Check Out: %s %s %s', $reserva->getNombre(),  $reserva->getUnit()->getNombre(), $reserva->getUnit()->getEstablecimiento()->getNombre()))
                ->setDescription($reserva->getEnlace())
                ->setUid('o-' . $reserva->getUid());
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
