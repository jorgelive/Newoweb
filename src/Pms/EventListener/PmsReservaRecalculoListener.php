<?php

declare(strict_types=1);

namespace App\Pms\EventListener;

use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsReserva;
use App\Pms\Service\Reserva\PmsReservaRecalculoService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;
use SplObjectStorage;

#[AsDoctrineListener(event: Events::onFlush, priority: -1000)]
#[AsDoctrineListener(event: Events::postFlush, priority: -1000)]
final class PmsReservaRecalculoListener
{
    /** @var array<int, true> */
    private array $reservaIds = [];

    /** @var SplObjectStorage<PmsReserva, null> */
    private SplObjectStorage $reservasSinId;

    private bool $secondFlushRunning = false;

    public function __construct(
        private readonly PmsReservaRecalculoService $service,
    ) {
        $this->reservasSinId = new SplObjectStorage();
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if ($this->secondFlushRunning) {
            return;
        }

        $em  = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $this->collectReservaId($entity, $uow);
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $this->collectReservaId($entity, $uow);
        }

        foreach ($uow->getScheduledCollectionUpdates() as $collection) {
            $owner = $collection->getOwner();
            if (is_object($owner)) {
                $this->collectReservaId($owner, $uow);
            }
        }

        foreach ($uow->getScheduledCollectionDeletions() as $collection) {
            $owner = $collection->getOwner();
            if (is_object($owner)) {
                $this->collectReservaId($owner, $uow);
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->secondFlushRunning) {
            return;
        }

        if ($this->reservaIds === [] && $this->reservasSinId->count() === 0) {
            return;
        }

        /** @var EntityManagerInterface $em */
        $em = $args->getObjectManager();

        $ids = array_keys($this->reservaIds);

        foreach ($this->reservasSinId as $reserva) {
            $id = $reserva->getId();
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return;
        }

        // limpiar antes del recalculo
        $this->reservaIds = [];
        $this->reservasSinId = new SplObjectStorage();

        $this->secondFlushRunning = true;
        try {
            // Ejecuta UPDATEs directos (DQL/DBAL) contra la BD.
            // Importante: NO llamamos a flush() aquí para evitar doble flush/loops.
            $this->service->recalcularDesdeEventos($ids, $em);
        } finally {
            $this->secondFlushRunning = false;
        }
    }

    private function collectReservaId(object $entity, UnitOfWork $uow): void
    {
        // 1) Si es la reserva directamente
        if ($entity instanceof PmsReserva) {
            if ($uow->isScheduledForDelete($entity)) {
                return;
            }

            $id = $entity->getId();
            if ($id !== null) {
                $this->reservaIds[$id] = true;
            } else {
                $this->reservasSinId->attach($entity);
            }
            return;
        }

        // 2) Si es un evento -> marcar su reserva padre
        if ($entity instanceof PmsEventoCalendario) {
            $reserva = $entity->getReserva();
            if ($reserva instanceof PmsReserva) {
                if ($uow->isScheduledForDelete($reserva)) {
                    return;
                }

                $id = $reserva->getId();
                if ($id !== null) {
                    $this->reservaIds[$id] = true;
                } else {
                    $this->reservasSinId->attach($reserva);
                }
            }
            return;
        }

        // 3) Fallback por si el owner expone getReserva()
        if (method_exists($entity, 'getReserva')) {
            $reserva = $entity->getReserva();
            if ($reserva instanceof PmsReserva) {
                if ($uow->isScheduledForDelete($reserva)) {
                    return;
                }

                $id = $reserva->getId();
                if ($id !== null) {
                    $this->reservaIds[$id] = true;
                } else {
                    $this->reservasSinId->attach($reserva);
                }
            }
        }
    }
}