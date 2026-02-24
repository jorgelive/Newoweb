<?php

declare(strict_types=1);

namespace App\Pms\EventListener\Queue;

use App\Exchange\Dispatch\RunExchangeTaskDispatch;
use App\Pms\Entity\PmsTarifaRango;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Service\Beds24\Queue\Beds24RatesPushQueueCreator;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsDoctrineListener(event: Events::onFlush, priority: 200)]
#[AsDoctrineListener(event: Events::postFlush, priority: 200)]
final class Beds24RatesPushQueueListener
{
    private array $queuedIdsForDispatch = [];

    public function __construct(
        private readonly Beds24RatesPushQueueCreator $queueCreator,
        private readonly MessageBusInterface $bus
    ) {}

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        if (!$em instanceof EntityManagerInterface) return;
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof PmsTarifaRango && $this->isValid($entity)) $this->processTarifaRango($uow, $entity);
        }
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof PmsTarifaRango && $this->isValid($entity)) $this->processTarifaRango($uow, $entity);
        }
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof PmsTarifaRango) $this->processTarifaRangoDeletion($uow, $entity);
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if (empty($this->queuedIdsForDispatch)) return;
        $idsBatch = array_values(array_unique($this->queuedIdsForDispatch));
        $this->queuedIdsForDispatch = [];
        $this->bus->dispatch(new RunExchangeTaskDispatch(taskName: 'rates_push', ids: $idsBatch));
    }

    private function isValid(PmsTarifaRango $rango): bool {
        return $rango->getUnidad() !== null && $rango->getFechaInicio() !== null && $rango->getFechaFin() !== null;
    }

    private function processTarifaRango(UnitOfWork $uow, PmsTarifaRango $rango): void
    {
        $changeSet = $uow->getEntityChangeSet($rango);
        $newUnidad = $rango->getUnidad();

        $from = $this->minDate($this->getOldValue($changeSet, 'fechaInicio'), $rango->getFechaInicio());
        $to   = $this->maxDate($this->getOldValue($changeSet, 'fechaFin'), $rango->getFechaFin());

        $isDelete = ($this->getOldValue($changeSet, 'activo') === true && $rango->isActivo() === false);

        $ids = $this->queueCreator->enqueueForInterval($newUnidad, $from, $to, $rango, $isDelete, $uow);
        $this->mergeIds($ids);

        // Si cambió la unidad, recalcular la anterior también
        $oldUnidad = $this->getOldValue($changeSet, 'unidad');
        if ($oldUnidad instanceof PmsUnidad && $oldUnidad !== $newUnidad) {
            $ids = $this->queueCreator->enqueueForInterval($oldUnidad, $from, $to, $rango, true, $uow);
            $this->mergeIds($ids);
        }
    }

    private function processTarifaRangoDeletion(UnitOfWork $uow, PmsTarifaRango $rango): void
    {
        $ids = $this->queueCreator->enqueueForInterval(
            $rango->getUnidad(),
            $rango->getFechaInicio(),
            $rango->getFechaFin(),
            $rango,
            true,
            $uow
        );
        $this->mergeIds($ids);
    }

    private function mergeIds(array $ids): void {
        if (!empty($ids)) $this->queuedIdsForDispatch = array_merge($this->queuedIdsForDispatch, $ids);
    }

    private function getOldValue(array $changeSet, string $field): mixed {
        return $changeSet[$field][0] ?? null;
    }

    private function minDate(?DateTimeInterface $a, ?DateTimeInterface $b): DateTimeInterface {
        if (!$a) return DateTimeImmutable::createFromInterface($b);
        if (!$b) return DateTimeImmutable::createFromInterface($a);
        return $a < $b ? DateTimeImmutable::createFromInterface($a) : DateTimeImmutable::createFromInterface($b);
    }

    private function maxDate(?DateTimeInterface $a, ?DateTimeInterface $b): DateTimeInterface {
        if (!$a) return DateTimeImmutable::createFromInterface($b);
        if (!$b) return DateTimeImmutable::createFromInterface($a);
        return $a > $b ? DateTimeImmutable::createFromInterface($a) : DateTimeImmutable::createFromInterface($b);
    }
}