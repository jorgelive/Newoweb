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

/**
 * Listener Beds24RatesPushQueueListener.
 * Detecta cambios en tarifas y rangos para sincronizar precios con Beds24.
 * Calcula intervalos inteligentes (unión de fechas) para cubrir expansiones o reducciones de rango.
 */
#[AsDoctrineListener(event: Events::onFlush, priority: 200)]
final class Beds24RatesPushQueueListener
{
    public function __construct(
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
                $this->processTarifaRango($uow, $entity);
            }
        }

        // 2. UPDATES
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof PmsTarifaRango && $this->isValid($entity)) {
                $this->processTarifaRango($uow, $entity);
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

    private function processTarifaRango(UnitOfWork $uow, PmsTarifaRango $rango): void
    {
        $changeSet = $uow->getEntityChangeSet($rango);

        // Valores Actuales
        $newUnidad = $rango->getUnidad();
        $newInicio = $rango->getFechaInicio();
        $newFin    = $rango->getFechaFin();

        // Valores Antiguos (ChangeSet)
        $oldUnidad = $this->getOldValue($changeSet, 'unidad');
        $oldInicio = $this->getOldValue($changeSet, 'fechaInicio');
        $oldFin    = $this->getOldValue($changeSet, 'fechaFin');

        // Normalización de Tipos
        $oldUnidadObj = $oldUnidad instanceof PmsUnidad ? $oldUnidad : null;
        $oldInicioObj = $oldInicio instanceof DateTimeInterface ? $oldInicio : null;
        $oldFinObj    = $oldFin instanceof DateTimeInterface ? $oldFin : null;

        // CASO A: Soft Delete (Activo pasó de true a false)
        $oldActivo = $this->getOldValue($changeSet, 'activo');
        $newActivo = $rango->isActivo();

        // Nota: isActivo() retorna bool, la comparación estricta es segura
        if ($oldActivo === true && $newActivo === false) {
            $this->queueCreator->enqueueForInterval(
                $oldUnidadObj ?? $newUnidad,
                $oldInicioObj ?? $newInicio,
                $oldFinObj ?? $newFin,
                $rango,
                true, // isDelete logic
                $uow
            );
            return;
        }

        // CASO B: Cambio de Unidad (Movimiento)
        // Se debe limpiar la unidad vieja y actualizar la nueva
        if ($oldUnidadObj instanceof PmsUnidad && $oldUnidadObj !== $newUnidad) {
            $this->queueCreator->enqueueForInterval(
                $oldUnidadObj,
                $oldInicioObj ?? $newInicio,
                $oldFinObj ?? $newFin,
                $rango,
                true, // Limpiar unidad vieja
                $uow
            );
        }

        // CASO C: Standard (Insert / Update de precios o fechas)
        // Calculamos la envoltura de fechas (MinStart -> MaxEnd) para asegurar que
        // si el rango se encogió, los días sobrantes se actualicen también.
        $from = $this->minDate($oldInicioObj, $newInicio);
        $to   = $this->maxDate($oldFinObj, $newFin);

        $this->queueCreator->enqueueForInterval(
            $newUnidad,
            $from,
            $to,
            $rango,
            false, // isUpdate/Insert logic
            $uow
        );
    }

    private function processTarifaRangoDeletion(UnitOfWork $uow, PmsTarifaRango $rango): void
    {
        $changeSet = $uow->getEntityChangeSet($rango);

        // En borrado, intentamos rescatar los valores del ChangeSet, si no, usamos los de la entidad
        $unidad = $this->getOldValue($changeSet, 'unidad') ?? $rango->getUnidad();
        $inicio = $this->getOldValue($changeSet, 'fechaInicio') ?? $rango->getFechaInicio();
        $fin    = $this->getOldValue($changeSet, 'fechaFin') ?? $rango->getFechaFin();

        if ($unidad instanceof PmsUnidad && $inicio instanceof DateTimeInterface && $fin instanceof DateTimeInterface) {
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

    /* ======================================================
     * HELPERS (Tipado Estricto)
     * ====================================================== */

    /**
     * @return mixed|null Retorna el valor antiguo del changeset o null si no existe.
     */
    private function getOldValue(array $changeSet, string $field): mixed
    {
        return $changeSet[$field][0] ?? null;
    }

    private function minDate(?DateTimeInterface $a, ?DateTimeInterface $b): DateTimeInterface
    {
        // Si no hay fecha antigua ($a), usamos la nueva ($b).
        if (!$a && $b) return DateTimeImmutable::createFromInterface($b)->setTime(0, 0, 0);
        // Si no hay fecha nueva ($b), usamos la antigua ($a).
        if ($a && !$b) return DateTimeImmutable::createFromInterface($a)->setTime(0, 0, 0);
        // Si ambas son nulas (caso raro), retornamos hoy por seguridad.
        if (!$a && !$b) return new DateTimeImmutable('today');

        $aa = DateTimeImmutable::createFromInterface($a)->setTime(0, 0, 0);
        $bb = DateTimeImmutable::createFromInterface($b)->setTime(0, 0, 0);

        return $aa < $bb ? $aa : $bb;
    }

    private function maxDate(?DateTimeInterface $a, ?DateTimeInterface $b): DateTimeInterface
    {
        if (!$a && $b) return DateTimeImmutable::createFromInterface($b)->setTime(0, 0, 0);
        if ($a && !$b) return DateTimeImmutable::createFromInterface($a)->setTime(0, 0, 0);
        if (!$a && !$b) return new DateTimeImmutable('today');

        $aa = DateTimeImmutable::createFromInterface($a)->setTime(0, 0, 0);
        $bb = DateTimeImmutable::createFromInterface($b)->setTime(0, 0, 0);

        return $aa > $bb ? $aa : $bb;
    }
}