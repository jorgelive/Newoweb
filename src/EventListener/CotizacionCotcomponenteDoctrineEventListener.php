<?php
namespace App\EventListener;

use App\Entity\CotizacionCotcomponente;
use App\Entity\CotizacionCotservicio;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;

class CotizacionCotcomponenteDoctrineEventListener
{
    private EntityManagerInterface $entityManager;

    function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function onFlush(OnFlushEventArgs $args)
    {
        $uow = $this->entityManager->getUnitOfWork();

        $entities = array_merge(
            $uow->getScheduledEntityInsertions(),
            $uow->getScheduledEntityUpdates()
        );

        foreach ($entities as $entity) {
            if (!($entity instanceof CotizacionCotcomponente)) {
                continue;
            }

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

            unset($newFechahoraInicio);
            unset($newFechahoraFin);

            if($modificado){
                $this->entityManager->persist($cotservicio);
                $metaData = $this->entityManager->getClassMetadata(CotizacionCotservicio::class);
                $uow->recomputeSingleEntityChangeSet($metaData, $cotservicio);
            }
        }

    }

}