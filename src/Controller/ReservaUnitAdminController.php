<?php

namespace App\Controller;

use App\Entity\ReservaChanel;
use App\Service\IcalGenerator;
use App\Service\MainVariableproceso;
use App\Entity\ReservaEstado;
use Google\Cloud\Translate\V2\TranslateClient;
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
                'doctrine.orm.default_entity_manager' => EntityManagerInterface::class,
                'App\Service\MainVariableproceso' => MainVariableproceso::class,
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

        $unitDL = $em->getRepository('App\Entity\ReservaUnit')->find($id);
        $unitDL->setLocale($request->getDefaultLocale());
        $em->refresh($unitDL);

        $descripcionDL = $unitDL->getDescripcion();
        $referenciaDL = $unitDL->getReferencia();

        $unitDL->setLocale($request->getLocale());
        $em->refresh($unitDL);

        $translate = new TranslateClient([
            'key' => $this->getParameter('google_translate_key')
        ]);

        if(!empty($descripcionDL)) {
            $descripcionTL = $translate->translate($descripcionDL, [
                'target' => $request->getLocale(),
                'source' => $request->getDefaultLocale()
            ]);
            $object->setDescripcion($descripcionTL['text']);
        }

        if(!empty($referenciaDL)) {
            $referenciaTL = $translate->translate($referenciaDL, [
                'target' => $request->getLocale(),
                'source' => $request->getDefaultLocale()
            ]);
            $object->setReferencia($referenciaTL['text']);
        }

        $existingObject = $this->admin->update($object);

        $this->addFlash('sonata_flash_success', 'Unidad traducida correctamente');

        return new RedirectResponse($this->admin->generateUrl('list'));

    }

    public function inventarioAction(Request $request = null): Response | RedirectResponse
    {
        $object = $this->assertObjectExists($request, true);
        \assert(null !== $object);

        $this->checkParentChildAssociation($request, $object);

        //$this->admin->checkAccess('show', $object);

        $preResponse = $this->preShow($request, $object);
        if(null !== $preResponse) {
            return $preResponse;
        }

        $this->admin->setSubject($object);

        $fields = $this->admin->getShow();

        //$template = $this->templateRegistry->getTemplate('show'); es privado en la clase padre
        $template = 'reserva_unit_admin/show.html.twig';

        return $this->renderWithExtraParams($template,
            [
                'object' => $object,
                'action' => 'inventario',
                'elements' => $fields,
            ]);

    }

    public function resumenAction(Request $request = null): Response | RedirectResponse
    {
        $object = $this->assertObjectExists($request, true);
        \assert(null !== $object);

        $this->checkParentChildAssociation($request, $object);

        //$this->admin->checkAccess('show', $object);

        $preResponse = $this->preShow($request, $object);
        if(null !== $preResponse) {
            return $preResponse;
        }

        $this->admin->setSubject($object);

        $fields = $this->admin->getShow();

        //$template = $this->templateRegistry->getTemplate('show'); es privado en la clase padre
        $template = 'reserva_unit_admin/show.html.twig';

        return $this->renderWithExtraParams($template,
            [
                'object' => $object,
                'action' => 'resumen',
                'elements' => $fields,
            ]);

    }

    public function icalicsAction(Request $request = null): Response
    {
        return $this->icalAction($request);
    }


    public function icalAction(Request $request = null): Response
    {
        $variableproceso = $this->container->get('App\Service\MainVariableproceso');

        $ahora = new \DateTime('now');

        $object = $this->assertObjectExists($request, true);
        if(!$object) {
            throw $this->createNotFoundException('Unable to find the object processing the request');
        }
        $id = $object->getId();

        $em = $this->container->get('doctrine.orm.default_entity_manager');
        $qb = $em->createQueryBuilder()
            ->select('rr')
            ->from('App\Entity\ReservaReserva', 'rr')
            ->where('rr.unit = :unit')
            ->andWhere('rr.estado in :estado')
            ->andWhere('DATE(rr.fechahorafin) >= :fechahorafin')
            ->orderBy('rr.fechahorainicio', 'ASC')
            ->setParameter('unit', $id)
            ->setParameter('estado', [ReservaEstado::DB_VALOR_CONFIRMADO, ReservaEstado::DB_VALOR_PAGO_PARCIAL, ReservaEstado::DB_VALOR_PAGO_TOTAL])
            ->setParameter('fechahorafin', $ahora->format('Y-m-d'));
        ;

        $reservas = $qb->getQuery()->getResult();

        $calendar = $this->container->get('App\Service\IcalGenerator')->setTimezone('America/Lima')->setProdid('-//OpenPeru//Cotservicio Calendar //ES')->createCalendar();

        $queriedfrom = gethostbyaddr($request->getClientIp());

        $variableproceso->prependtofile('debug/reservasuniticalhosts.txt', $ahora->format('Y-m-d H:i:s') . ' Para: ' . $id . ', Consultado desde '. $queriedfrom . "\n");

        foreach($reservas as $reserva){

            /*if($reserva->getChanel()->getId() == ReservaChanel::DB_VALOR_BOOKING && str_contains($queriedfrom, 'booking.com')){
                $variableproceso->prependtofile('debug/reservasuniticalhosts.txt', $ahora->format('Y-m-d H:i:s') . '         Omitiendo reserva de Booking.com para ' . $reserva->getFechahorainicio()->format('Y-m-d') . ', con id: '. $reserva->getId() . "\n");
                //Si la consulta es de booking y la reserva es de booking no la mostramos
                continue;
            }*/

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

}
