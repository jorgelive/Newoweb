<?php
namespace App\EventListener;

use App\Entity\CotizacionCotcomponente;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;

class CotizacionCotcomponenteDoctrineEventListener
{
    private EntityManagerInterface $entityManager;

    function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function postPersist(PostPersistEventArgs $args)
    {
        $entity = $args->getObject();
        if($entity instanceof CotizacionCotcomponente){
            $this->actualizarCotservicio($entity);
        }
    }

    public function postUpdate(PostUpdateEventArgs $args)
    {
        $entity = $args->getObject();
        if($entity instanceof CotizacionCotcomponente){
            $this->actualizarCotservicio($entity);
        }
    }

    private function actualizarCotservicio(CotizacionCotcomponente $entity)
    {
        $cotservicio = $entity->getCotservicio();

        $oldFechahoraInicio = $cotservicio->getFechahorainicio();
        $oldFechahoraFin = $cotservicio->getFechahorafin();

        $cotcomponentes = $cotservicio->getCotcomponentes();

        foreach ($cotcomponentes as $cotcomponente){
            if(!isset($newFechahoraInicio)){
                $newFechahoraInicio = $cotcomponente->getFechahorainicio();
            }elseif($cotcomponente->getFechahorainicio() < $newFechahoraInicio){
                $newFechahoraInicio = $cotcomponente->getFechahorainicio();
            }

            if(!isset($newFechahoraFin)){
                $newFechahoraFin = $cotcomponente->getFechahorafin();
            }elseif($cotcomponente->getFechahorafin() > $newFechahoraFin){
                $newFechahoraFin = $cotcomponente->getFechahorafin();
            }
        }

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
            $this->entityManager->persist($cotservicio);
            //$this->entityManager->flush();
        }
    }
}