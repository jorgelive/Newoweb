<?php
namespace App\EventListener;

use App\Entity\CotizacionCotservicio;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;

class CotizacionCotservicioDoctrineEventListener
{

    private EntityManagerInterface $entityManager;

    function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function postPersist(PostPersistEventArgs $args)
    {
        $entity = $args->getObject();
        if($entity instanceof CotizacionCotservicio){
            $this->actualizarCotizacion($entity);
        }
    }

    public function postUpdate(PostUpdateEventArgs $args)
    {
        $entity = $args->getObject();

        if($entity instanceof CotizacionCotservicio){
            $this->actualizarCotizacion($entity);
        }
    }

    private function actualizarCotizacion(CotizacionCotservicio $entity)
    {
        $cotizacion = $entity->getCotizacion();

        $oldFechahoraIngreso = $cotizacion->getFechaingreso();
        $oldFechahoraSalida = $cotizacion->getFechasalida();
        //$newFechahoraIngreso = $cotizacion->getCotservicios()->first()->getFechahorainicio();
        //$newFechahoraSalida = $cotizacion->getCotservicios()->last()->getFechahorafin();

        $cotservicios = $cotizacion->getCotservicios();

        foreach ($cotservicios as $cotservicio){
            if(!isset($newFechahoraIngreso)){
                $newFechahoraIngreso = $cotservicio->getFechahorainicio();
            }elseif($cotservicio->getFechahorainicio() < $newFechahoraIngreso){
                $newFechahoraIngreso = $cotservicio->getFechahorainicio();
            }

            if(!isset($newFechahoraSalida)){
                $newFechahoraSalida = $cotservicio->getFechahorafin();
            }elseif($cotservicio->getFechahorafin() > $newFechahoraSalida){
                $newFechahoraSalida = $cotservicio->getFechahorafin();
            }
        }

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
            $this->entityManager->persist($cotizacion);
            $this->entityManager->flush();
        }
    }
}