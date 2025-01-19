<?php

namespace App\Controller;

use App\Entity\ReservaChannel;
use App\Entity\ReservaEstado;
use App\Service\IcalGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

class ReservaReservaAdminController extends CRUDAdminController
{

    private IcalGenerator $icalGenerator;

    private EntityManagerInterface $entityManager;

    function __construct(IcalGenerator $icalGenerator, EntityManagerInterface $entityManager)
    {
        $this->icalGenerator = $icalGenerator;
        $this->entityManager = $entityManager;
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

    public function clonarAction(Request $request): Response
    {
        $object = $this->assertObjectExists($request, true);

        $this->admin->checkAccess('create', $object);

        $newObject = clone $object;

        $newObject->setUid('cl-' . $object->getUid());
        $newObject->setChannel($this->entityManager->getReference('App\Entity\ReservaChannel', ReservaChannel::DB_VALOR_DIRECTO));
        $newObject->setEstado($this->entityManager->getReference('App\Entity\ReservaEstado', ReservaEstado::DB_VALOR_CONFIRMADO));
        $newObject->setUnitnexo(null);

        $newObject->setNombre($object->getNombre() . ' (Clone)');
        $this->admin->create($newObject);

        $this->addFlash('sonata_flash_success', 'Reserva clonada correctamente');

        return new RedirectResponse($this->admin->generateUrl('edit', ['id' => $newObject->getId()]));
    }

    public function extenderAction(Request $request = null)
    {
        $object = $this->assertObjectExists($request, true);
        $id = $object->getId();

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
        $newObject->setChannel($this->entityManager->getReference('App\Entity\ReservaChannel', ReservaChannel::DB_VALOR_DIRECTO));
        $newObject->setEstado($this->entityManager->getReference('App\Entity\ReservaEstado', ReservaEstado::DB_VALOR_CONFIRMADO));
        $newObject->setUnitnexo(null);
        $newObject->setFechahorafin($nuevaFechaFinal);
        $newObject->setNombre($object->getNombre() . ' (Adicional)');
        $this->admin->create($newObject);

        $this->addFlash('sonata_flash_success', 'Reserva extendida correctamente');

        return new RedirectResponse($this->admin->generateUrl('edit', ['id' => $newObject->getId()]));

    }

    public function icalAction(Request $request = null): Response
    {
        $ahora = new \DateTime('now');

        $qb = $this->entityManager->createQueryBuilder()
            ->select('rr')
            ->from('App\Entity\ReservaReserva', 'rr')
            ->where('rr.estado in (:estado)')
            ->andWhere('DATE(rr.fechahorafin) >= :fechahorafin')
            ->orderBy('rr.fechahorainicio', 'ASC')
            ->setParameter('estado', [ReservaEstado::DB_VALOR_CONFIRMADO, ReservaEstado::DB_VALOR_PAGO_PARCIAL, ReservaEstado::DB_VALOR_PAGO_TOTAL, ReservaEstado::DB_VALOR_PARA_CANCELACION])
            ->setParameter('fechahorafin', $ahora->sub(new \DateInterval('P7D'))->format('Y-m-d'));
        ;

        $reservas = $qb->getQuery()->getResult();

        $calendar = $this->icalGenerator->setTimezone('America/Lima')->setProdid('-//OpenPeru//Cotservicio Calendar //ES')->createCalendar();

        foreach($reservas as $reserva){

            $fechainicioMasUno = new \DateTime($reserva->getFechahorainicio()->format('Y-m-d H:i') . '+1 hour');
            $fechafinMasUno = new \DateTime($reserva->getFechahorafin()->format('Y-m-d H:i') . '+1 hour');

            $tempEvent = $this->icalGenerator
                ->createCalendarEvent()
                ->setStart($reserva->getFechahorainicio())
                ->setEnd($fechainicioMasUno)
                ->setSummary(sprintf('Check In: %s %s %s', $reserva->getNombre(),  $reserva->getUnit()->getNombre(), $reserva->getUnit()->getEstablecimiento()->getNombre()))
                ->setDescription($reserva->getEnlace())
                ->setUid('i-' . $reserva->getUid());
            $calendar->addEvent($tempEvent);

            $tempEvent = $this->icalGenerator
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

}
