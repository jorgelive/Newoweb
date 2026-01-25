<?php
declare(strict_types=1);

namespace App\Pms\Service\Beds24\Queue;

use App\Exchange\Service\Context\SyncContext;
use App\Pms\Entity\PmsBeds24Endpoint;
use App\Pms\Entity\PmsRatesPushQueue;
use App\Pms\Entity\PmsTarifaRango;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Entity\PmsUnidadBeds24Map;
use App\Pms\Repository\PmsRatesPushQueueRepository;
use App\Pms\Service\Tarifa\Engine\TarifaPricingEngine;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;

class Beds24RatesPushQueueCreator
{
    private const ACCION_ENDPOINT = 'CALENDAR_POST';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TarifaPricingEngine $pricingEngine,
        private readonly SyncContext $syncContext,
        private readonly PmsRatesPushQueueRepository $repo
    ) {
    }

    public function enqueueForInterval(
        PmsUnidad $unidad,
        DateTimeInterface $start,
        DateTimeInterface $end,
        ?PmsTarifaRango $dirtyRango = null,
        bool $isDelete = false,
        ?UnitOfWork $uow = null
    ): void {
        // Bloqueo global de sincronización (Context Check)
        if ($this->syncContext->isPush()) {
            return;
        }

        $fromDay = $this->toDay($start);
        $toExclusive = $this->toDay($end);

        if ($toExclusive <= $fromDay) {
            return;
        }

        // 1. Obtención de datos base (Ranges)
        $rangos = $this->fetchDbRangos($unidad, $fromDay, $toExclusive);

        // 2. Manipulación de rangos en memoria (Dirty check logic)
        if ($dirtyRango !== null) {
            if ($isDelete) {
                // Si es delete, lo sacamos de la lista para que el Engine recalcule sin él
                $rangos = array_values(array_filter($rangos, fn($r) => $r !== $dirtyRango));
            } elseif ($dirtyRango->getUnidad() === $unidad && ($dirtyRango->isActivo() ?? false)) {
                $rStart = $this->toDay($dirtyRango->getFechaInicio());
                $rEnd = $this->toDay($dirtyRango->getFechaFin());

                if ($rEnd > $fromDay && $rStart < $toExclusive) {
                    if (!in_array($dirtyRango, $rangos, true)) {
                        $rangos[] = $dirtyRango;
                    }
                }
            }
        }

        $sourceToRango = [];
        foreach ($rangos as $rr) {
            $sourceToRango[$this->expectedSourceIdForRango($rr)] = $rr;
        }

        // 3. Engine de Precios (Cálculo lógico de intervalos)
        $logicalRanges = $this->pricingEngine->buildLogicalRangesForIntervalWithFallback(
            $rangos,
            $fromDay,
            $toExclusive,
            $this->createRangeAccessor(),
            null,
            $this->createFallbackProvider($unidad)
        );

        if (empty($logicalRanges)) return;

        // 4. Validación de Infraestructura (Mapas y Endpoints)
        $endpoint = $this->resolveEndpoint();
        if (!$endpoint) return;

        $maps = $this->fetchActiveMaps($unidad);
        if (empty($maps)) return;

        // 5. Cargar colas pendientes existentes para deduplicar/actualizar
        $pendingQueues = $this->repo->findPendingForUnit(
            $unidad->getId(),
            $fromDay->modify('-1 day'),
            $toExclusive->modify('+1 day')
        );

        // 6. Proceso de Aplanado (Flattening)
        $now = new DateTimeImmutable();

        foreach ($maps as $map) {

            // [PROTECCIÓN ADICIONAL] Si el mapa se está borrando, saltamos para evitar errores de cascada.
            if ($uow !== null && $uow->isScheduledForDelete($map)) {
                continue;
            }

            foreach ($logicalRanges as $lr) {

                // Determinar el rango "Ganador" (Source Traceability)
                $winnerSourceId = $lr->getSourceId();
                $isBaseWinner = is_string($winnerSourceId) && str_starts_with($winnerSourceId, 'base:');

                $winnerRango = null;
                if (!$isBaseWinner) {
                    // --- MODIFICACIÓN DE SEGURIDAD PARA BORRADO ---
                    // Si el ID viene del engine, lo usamos.
                    // Si no, usamos $dirtyRango SOLO si NO estamos borrando.
                    // Si estamos borrando ($isDelete=true), el fallback es NULL.
                    $fallback = $isDelete ? null : $dirtyRango;
                    $winnerRango = $sourceToRango[$winnerSourceId] ?? $fallback;
                }

                $moneda = $isBaseWinner ? $unidad->getTarifaBaseMoneda() : $winnerRango?->getMoneda();

                // Buscar si ya existe una tarea pendiente compatible para este MAPA específico
                $queue = $this->findCompatibleQueue($pendingQueues, $lr, $winnerRango, $map);

                $isNew = false;
                if ($queue === null) {
                    $isNew = true;
                    $queue = new PmsRatesPushQueue();
                    $queue
                        ->setUnidad($unidad)
                        ->setUnidadBeds24Map($map)
                        ->setEndpoint($endpoint)
                        ->setFechaInicio($lr->getStart())
                        ->setFechaFin($lr->getEnd());

                    // Solo asignamos el Rango si existe y no se está borrando
                    if ($winnerRango) {
                        $queue->setTarifaRango($winnerRango);
                    }

                    $this->em->persist($queue);
                    $pendingQueues[] = $queue;
                } else {
                    // Si existe, extendemos o reducimos fechas
                    $queue->setFechaInicio($this->minDate($queue->getFechaInicio(), $lr->getStart()));
                    $queue->setFechaFin($this->maxDate($queue->getFechaFin(), $lr->getEnd()));

                    if ($winnerRango) {
                        $queue->setTarifaRango($winnerRango);
                    }
                }

                // Actualizar valores
                $queue
                    ->setPrecio(number_format($lr->getPrice(), 2, '.', ''))
                    ->setMinStay($lr->getMinStay())
                    ->setMoneda($moneda)
                    ->setStatus(PmsRatesPushQueue::STATUS_PENDING)
                    ->setRunAt($now)
                    ->setEffectiveAt($now)
                    ->setRetryCount(0)
                    ->setFailedReason(null);

                if ($uow !== null) {
                    $meta = $this->em->getClassMetadata(PmsRatesPushQueue::class);
                    $isNew ? $uow->computeChangeSet($meta, $queue) : $uow->recomputeSingleEntityChangeSet($meta, $queue);
                }
            }
        }
    }

    // =========================================================================
    //  HELPERS (Sin cambios)
    // =========================================================================

    private function findCompatibleQueue(
        array $candidates,
              $lr,
        ?PmsTarifaRango $winnerRango,
        PmsUnidadBeds24Map $targetMap
    ): ?PmsRatesPushQueue {
        $qStart = $this->toDay($lr->getStart());
        $qEnd = $this->toDay($lr->getEnd());

        foreach ($candidates as $q) {
            /** @var PmsRatesPushQueue $q */

            if ($q->getUnidadBeds24Map() !== $targetMap) {
                continue;
            }

            if ($winnerRango === null) {
                if ($q->getTarifaRango() !== null) continue;
            } else {
                $qRango = $q->getTarifaRango();
                if ($qRango !== $winnerRango && $qRango?->getId() !== $winnerRango->getId()) continue;
            }

            if (abs((float)$q->getPrecio() - (float)$lr->getPrice()) > 0.001) continue;
            if ($q->getMinStay() !== $lr->getMinStay()) continue;

            $cStart = $this->toDay($q->getFechaInicio());
            $cEnd = $this->toDay($q->getFechaFin());

            $overlap = ($cStart < $qEnd) && ($cEnd > $qStart);
            $touch = ($cEnd == $qStart) || ($qEnd == $cStart);

            if ($overlap || $touch) return $q;
        }
        return null;
    }

    private function resolveEndpoint(): ?PmsBeds24Endpoint
    {
        return $this->em->getRepository(PmsBeds24Endpoint::class)
            ->findOneBy(['accion' => self::ACCION_ENDPOINT, 'activo' => true]);
    }

    private function fetchActiveMaps(PmsUnidad $u): array
    {
        return $this->em->getRepository(PmsUnidadBeds24Map::class)
            ->findBy(['pmsUnidad' => $u, 'activo' => true]);
    }

    private function fetchDbRangos(PmsUnidad $u, $from, $to): array
    {
        return $this->em->getRepository(PmsTarifaRango::class)
            ->findOverlappingForInterval($from, $to);
    }

    private function expectedSourceIdForRango(PmsTarifaRango $r): string
    {
        return $r->getId() ? 'id:' . $r->getId() : 'id:tmp:' . spl_object_id($r);
    }

    private function createRangeAccessor(): callable
    {
        return fn(PmsTarifaRango $r) => [
            'start' => $this->toDay($r->getFechaInicio()),
            'end' => $this->toDay($r->getFechaFin()),
            'price' => $r->getPrecio(),
            'minStay' => $r->getMinStay(),
            'currency' => $r->getMoneda()?->getCodigo(),
            'important' => $r->isImportante(),
            'weight' => $r->getPeso(),
            'id' => $r->getId() ?: ('tmp:'.spl_object_id($r))
        ];
    }

    private function createFallbackProvider(PmsUnidad $u): callable
    {
        return fn($d) => $u->isTarifaBaseActiva() ? [
            'price' => $u->getTarifaBasePrecio(),
            'minStay' => $u->getTarifaBaseMinStay(),
            'currency' => $u->getTarifaBaseMonedaOrFail()->getCodigo(),
            'sourceId' => 'base:unidad:' . $u->getId()
        ] : null;
    }

    private function toDay(DateTimeInterface $dt): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($dt)->setTime(0, 0, 0);
    }

    private function minDate($a, $b) { return $a < $b ? $a : $b; }
    private function maxDate($a, $b) { return $a > $b ? $a : $b; }
}