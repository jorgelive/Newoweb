<?php
namespace App\EventListener;

use App\Entity\CotizacionCotservicio;
use Doctrine\ORM\Event\LifecycleEventArgs;

class CotizacionCotservicioDoctrineEventListener
{

    public function postPersist(LifecycleEventArgs $args)
    {
        $entity = $args->getEntity();
        if($entity instanceof CotizacionCotservicio){
            $this->actualizarCotizacion($args, $entity);
        }
    }

    public function postUpdate(LifecycleEventArgs $args)
    {
        $entity = $args->getEntity();

        if($entity instanceof CotizacionCotservicio){
            $this->actualizarCotizacion($args, $entity);
        }
    }

    private function actualizarCotizacion(LifecycleEventArgs $args, CotizacionCotservicio $entity)
    {
        $cotizacion = $entity->getCotizacion();

        $oldFechahoraIngreso = $cotizacion->getFechaingreso();
        $oldFechahoraSalida = $cotizacion->getFechasalida();
        $newFechahoraIngreso = $cotizacion->getCotservicios()->first()->getFechahorainicio();
        $newFechahoraSalida = $cotizacion->getCotservicios()->last()->getFechahorafin();
        $modificado = false;
        if($oldFechahoraIngreso != $newFechahoraIngreso ){
            $modificado = true;
            $cotizacion->setFechaingreso($newFechahoraIngreso);
        }
        if($oldFechahoraSalida != $newFechahoraSalida ){
            $modificado = true;
            $cotizacion->setFechasalida($newFechahoraSalida);
        }

        if($modificado){
            $em = $args->getEntityManager();
            $em->persist($cotizacion);
            $em->flush();
        }
    }
}