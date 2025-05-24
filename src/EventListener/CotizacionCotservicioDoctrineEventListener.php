<?php
namespace App\EventListener;

use App\Entity\CotizacionCotcomponente;
use App\Entity\CotizacionCotizacion;
use App\Entity\CotizacionCotservicio;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;

class CotizacionCotservicioDoctrineEventListener
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
            if (!($entity instanceof CotizacionCotservicio)) {
                continue;
            }

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

            unset($newFechahoraIngreso);
            unset($newFechahoraSalida);

            if($modificado){
                $this->entityManager->persist($cotizacion);

                $metaData = $this->entityManager->getClassMetadata(CotizacionCotizacion::class);
                $uow->recomputeSingleEntityChangeSet($metaData, $cotizacion);

            }

        }

    }

}