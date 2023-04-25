<?php
namespace App\EventListener;

use App\Entity\CotizacionCotcomponente;
use Doctrine\ORM\Event\LifecycleEventArgs;

class CotizacionCotcomponenteDoctrineEventListener
{

    public function postPersist(LifecycleEventArgs $args)
    {
        $entity = $args->getEntity();
        if($entity instanceof CotizacionCotcomponente){
            $this->actualizarCotservicio($args, $entity);
        }
    }

    public function postUpdate(LifecycleEventArgs $args)
    {
        $entity = $args->getEntity();
        if($entity instanceof CotizacionCotcomponente){
            $this->actualizarCotservicio($args, $entity);
        }
    }

    private function actualizarCotservicio(LifecycleEventArgs $args, CotizacionCotcomponente $entity)
    {
        $cotservicio = $entity->getCotservicio();

        $oldFechahoraInicio = $cotservicio->getFechahorainicio();
        $oldFechahoraFin = $cotservicio->getFechahorafin();
        $newFechahoraInicio = $cotservicio->getCotcomponentes()->first()->getFechahorainicio();
        $cotcomponentes = $cotservicio->getCotcomponentes();
        foreach ($cotcomponentes as $cotcomponente){
            if(!isset($newFechahoraFin) || $cotcomponente->getFechahorafin() > $newFechahoraFin){
                $newFechahoraFin = $cotcomponente->getFechahorafin();
            }

        }
        //$newFechahoraFin = $cotservicio->getCotcomponentes()->last()->getFechahorafin();
        $modificado = false;
        if($oldFechahoraInicio != $newFechahoraInicio ){
            $modificado = true;
            $cotservicio->setFechahorainicio($newFechahoraInicio);
        }
        if($oldFechahoraFin != $newFechahoraFin ){
            $modificado = true;
            $cotservicio->setFechahorafin($newFechahoraFin);
        }

        if($modificado){
            $em = $args->getEntityManager();
            $em->persist($cotservicio);
            $em->flush();
        }
    }
}