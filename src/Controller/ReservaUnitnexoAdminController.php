<?php

namespace App\Controller;

use App\Entity\ReservaUnitnexo;
use App\Service\IcalGenerator;
use App\Service\MainVariableproceso;
use App\Entity\ReservaEstado;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;




class ReservaUnitnexoAdminController extends CRUDAdminController
{

    private IcalGenerator $icalGenerator;
    private MainVariableproceso $variableproceso;
    private EntityManagerInterface $entityManager;
    private ?ReservaUnitnexo $reservaUnitnexo;

    function __construct(IcalGenerator $icalGenerator, MainVariableproceso $variableproceso, EntityManagerInterface $entityManager)
    {
        $this->icalGenerator = $icalGenerator;
        $this->variableproceso = $variableproceso;
        $this->entityManager = $entityManager;
    }

    public function icalicsAction(Request $request = null): Response
    {
        return $this->icalAction($request);
    }

    public function icalAction(Request $request = null): Response
    {

        $ahora = new \DateTime('now');

        $this->reservaUnitnexo = $this->assertObjectExists($request, true);
        if(!$this->reservaUnitnexo) {
            throw $this->createNotFoundException('Unable to find the object processing the request');
        }
        $id = $this->reservaUnitnexo->getUnit()->getId();
        if(!empty($this->reservaUnitnexo->getRelated())){
            $idsNexoActual = explode(',', $this->reservaUnitnexo->getRelated());
        }else{
            $idsNexoActual = [];
        }

        $idsNexoActual[] = $this->reservaUnitnexo->getId();

        $qb = $this->entityManager->createQueryBuilder()
            ->select('rr')
            ->from('App\Entity\ReservaReserva', 'rr')
            ->where('rr.unit = :unit')
            ->andWhere('rr.estado in (:estado)')
            ->andWhere('DATE(rr.fechahorafin) >= :fechahorafin')
            ->orderBy('rr.fechahorainicio', 'ASC')
            ->setParameter('unit', $id)
            ->setParameter('estado', [ReservaEstado::DB_VALOR_CONFIRMADO, ReservaEstado::DB_VALOR_PAGO_PARCIAL, ReservaEstado::DB_VALOR_PAGO_TOTAL])
            ->setParameter('fechahorafin', $ahora->format('Y-m-d'));
        ;

        $reservas = $qb->getQuery()->getResult();

        $calendar = $this->icalGenerator->setTimezone('America/Lima')->setProdid('-//OpenPeru//Cotservicio Calendar //ES')->createCalendar();

        $queriedfrom = gethostbyaddr($request->getClientIp());

        $this->variableproceso->prependtofile('debug/reservasunitnexoicalhosts.txt', $ahora->format('Y-m-d H:i:s') . ' Para: ' . $this->reservaUnitnexo->getUnit()->getEstablecimiento()->getNombre() . ' '. $this->reservaUnitnexo->getUnit()->getNombre() . ' (unidad:' . $id . ')'. ', canal: '. $this->reservaUnitnexo->getChanel()->getNombre() . ', nexo: ' . $this->reservaUnitnexo->getId() . ', desde: ' . $queriedfrom . "\n");

        foreach($reservas as $reserva){
            //algunas reservas no tienen nexo porque son directas
            if(!empty($reserva->getUnitnexo()) && in_array($reserva->getUnitnexo()->getId(), $idsNexoActual)){
                $this->variableproceso->prependtofile('debug/reservasunitnexoicalhosts.txt', '                  Omitiendo reserva del dia: ' . $reserva->getFechahorainicio()->format('Y-m-d') . ', con id: '. $reserva->getId() . ', del nexo: ' . $reserva->getUnitnexo()->getId() . ', huesped: ' . $reserva->getNombre() ."\n");
                //Si la consulta es del mismo nexo o los "related"
                continue;
            }

            $fechainicio = new \DateTime($reserva->getFechahorainicio()->format('Y-m-d'));
            $fechafin = new \DateTime($reserva->getFechahorafin()->format('Y-m-d'));

            $tempEvent = $this->icalGenerator
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
