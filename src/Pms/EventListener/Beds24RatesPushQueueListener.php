<?php
declare(strict_types=1);

namespace App\Pms\EventListener;

use App\Pms\Entity\PmsTarifaRango;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Service\Beds24\Queue\Beds24RatesPushQueueCreator;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;

#[AsDoctrineListener(event: Events::onFlush, priority: 200)]
final class Beds24RatesPushQueueListener
{
    public function __construct(
        // Inyectamos tu nuevo servicio
        private readonly Beds24RatesPushQueueCreator $queueCreator
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        if (!$em instanceof EntityManagerInterface) {
            return;
        }

        $uow = $em->getUnitOfWork();

        // 1. INSERTIONS
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof PmsTarifaRango && $this->isValid($entity)) {
                $this->processTarifaRango($uow, $entity, true);
            }
        }

        // 2. UPDATES
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof PmsTarifaRango && $this->isValid($entity)) {
                $this->processTarifaRango($uow, $entity, false);
            }
        }

        // 3. DELETIONS
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof PmsTarifaRango) {
                $this->processTarifaRangoDeletion($uow, $entity);
            }
        }
    }

    private function isValid(PmsTarifaRango $rango): bool
    {
        return $rango->getUnidad() !== null
            && $rango->getFechaInicio() !== null
            && $rango->getFechaFin() !== null;
    }

    private function processTarifaRango(UnitOfWork $uow, PmsTarifaRango $rango, bool $isInsert): void
    {
        $changeSet = $uow->getEntityChangeSet($rango);

        $newUnidad = $rango->getUnidad();
        $newInicio = $rango->getFechaInicio();
        $newFin    = $rango->getFechaFin();

        // Detectar valores viejos para recálculo correcto en UPDATE
        $oldUnidad = $this->getOldValue($changeSet, 'unidad');
        $oldInicio = $this->getOldValue($changeSet, 'fechaInicio');
        $oldFin    = $this->getOldValue($changeSet, 'fechaFin');

        $oldUnidadObj = $oldUnidad instanceof PmsUnidad ? $oldUnidad : null;
        $oldInicioObj = $oldInicio instanceof DateTimeInterface ? $oldInicio : null;
        $oldFinObj    = $oldFin instanceof DateTimeInterface ? $oldFin : null;

        // A. Soft Delete (activo true -> false)
        $oldActivo = $this->getOldValue($changeSet, 'activo');
        $newActivo = (bool) ($rango->isActivo() ?? false);

        if (($oldActivo === true) && ($newActivo === false)) {
            $this->queueCreator->enqueueForInterval(
                $oldUnidadObj ?? $newUnidad,
                $oldInicioObj ?? $newInicio,
                $oldFinObj ?? $newFin,
                $rango,
                true, // isDelete
                $uow  // ✅ IMPORTANTE: Pasamos UoW
            );
            return;
        }

        // B. Cambio de Unidad (Move)
        if ($oldUnidadObj instanceof PmsUnidad && $oldUnidadObj !== $newUnidad) {
            // Recalcular hueco en unidad vieja
            $this->queueCreator->enqueueForInterval(
                $oldUnidadObj,
                $oldInicioObj ?? $newInicio,
                $oldFinObj ?? $newFin,
                $rango,
                true,
                $uow
            );
        }

        // C. Standard (Insert/Update en unidad actual)
        $from = $this->minDate($oldInicioObj, $newInicio);
        $to   = $this->maxDate($oldFinObj, $newFin);

        $this->queueCreator->enqueueForInterval(
            $newUnidad,
            $from,
            $to,
            $rango,
            false,
            $uow // ✅ Pasamos UoW
        );
    }

    private function processTarifaRangoDeletion(UnitOfWork $uow, PmsTarifaRango $rango): void
    {
        $changeSet = $uow->getEntityChangeSet($rango);

        $unidad = $this->getOldValue($changeSet, 'unidad') ?? $rango->getUnidad();
        $inicio = $this->getOldValue($changeSet, 'fechaInicio') ?? $rango->getFechaInicio();
        $fin    = $this->getOldValue($changeSet, 'fechaFin') ?? $rango->getFechaFin();

        if ($unidad instanceof PmsUnidad && $inicio && $fin) {
            $this->queueCreator->enqueueForInterval(
                $unidad,
                $inicio,
                $fin,
                $rango,
                true, // isDelete
                $uow
            );
        }
    }

    // ... Helpers (getOldValue, minDate, maxDate, toDay) ...
    private function getOldValue(array $cs, string $f) { return $cs[$f][0] ?? null; }
    private function minDate($a, $b) {
        if (!$a) return DateTimeImmutable::createFromInterface($b)->setTime(0,0,0);
        $aa = DateTimeImmutable::createFromInterface($a)->setTime(0,0,0);
        $bb = DateTimeImmutable::createFromInterface($b)->setTime(0,0,0);
        return $aa < $bb ? $aa : $bb;
    }
    private function maxDate($a, $b) {
        if (!$a) return DateTimeImmutable::createFromInterface($b)->setTime(0,0,0);
        $aa = DateTimeImmutable::createFromInterface($a)->setTime(0,0,0);
        $bb = DateTimeImmutable::createFromInterface($b)->setTime(0,0,0);
        return $aa > $bb ? $aa : $bb;
    }
}