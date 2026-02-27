<?php

declare(strict_types=1);

namespace App\Pms\Service\Beds24\Queue;

use App\Exchange\Entity\ExchangeEndpoint;
use App\Exchange\Enum\ConnectivityProvider;
use App\Exchange\Service\Context\SyncContext;
use App\Pms\Entity\PmsRatesPushQueue;
use App\Pms\Entity\PmsTarifaRango;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Entity\PmsUnidadBeds24Map;
use App\Pms\Factory\PmsRatesPushQueueFactory;
use App\Pms\Repository\PmsRatesPushQueueRepository;
use App\Pms\Repository\PmsTarifaRangoRepository;
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
        private readonly PmsRatesPushQueueRepository $queueRepo,
        private readonly PmsTarifaRangoRepository $rangoRepo,
        private readonly PmsRatesPushQueueFactory $factory
    ) {}

    public function enqueueForInterval(
        PmsUnidad $unidad,
        DateTimeInterface $start,
        DateTimeInterface $end,
        ?PmsTarifaRango $dirtyRango = null,
        bool $isDelete = false,
        ?UnitOfWork $uow = null
    ): array {
        if ($this->syncContext->isPush()) return [];

        $fromDay = $this->toDay($start);
        $toExclusive = $this->toDay($end);

        if ($toExclusive <= $fromDay) return [];

        $endpoint = $this->resolveEndpoint();
        $maps = $this->fetchActiveMaps($unidad);
        if (!$endpoint || empty($maps)) return [];

        // 1. SMART FLATTENING
        $allPending = $this->queueRepo->findPendingForUnit((string)$unidad->getId(), $fromDay, $toExclusive);
        [$recalcFrom, $recalcTo] = $this->expandWindowIteratively($allPending, $fromDay, $toExclusive);

        // 2. INVALIDACIÓN
        /** @var PmsUnidadBeds24Map[] $maps */
        foreach ($maps as $map) {
            $this->invalidateQueuesInWindow(
                queues: $allPending,
                start: $recalcFrom,
                end: $recalcTo,
                map: $map,
                uow: $uow
            );
        }

        // 3. CARGA DE DATOS (Usando el repo corregido)
        // Ahora sí traerá la tarifa de $52 gracias al fix de u.id
        $rangos = $this->rangoRepo->findOverlappingForUnidadAndInterval(
            unidad: $unidad,
            from: $recalcFrom,
            to: $recalcTo
        );

        // 4. GESTIÓN DE MEMORIA
        if ($dirtyRango !== null) {
            $dirtyId = (string)$dirtyRango->getId();

            // Quitamos la versión de BD de la tarifa que estamos tocando ($130)
            // La tarifa de $52 (otro ID) SE MANTIENE en el array
            $rangos = array_filter($rangos, fn(PmsTarifaRango $r) => (string)$r->getId() !== $dirtyId);

            if (!$isDelete && ($dirtyRango->isActivo() ?? false)) {
                $rangos[] = $dirtyRango;
            }
            $rangos = array_values($rangos);
        }

        // 5. MOTOR DE PRECIOS
        $logicalRanges = $this->pricingEngine->buildLogicalRangesForIntervalWithFallback(
            $rangos,
            $recalcFrom,
            $recalcTo,
            $this->createRangeAccessor(),
            null,
            $this->createFallbackProvider($unidad)
        );

        if (empty($logicalRanges)) return [];

        // 6. CREACIÓN
        $sourceToRango = [];
        foreach ($rangos as $r) {
            $sourceToRango[$this->getSourceIdForRango($r)] = $r;
        }

        $createdIds = [];
        $now = new DateTimeImmutable();

        /** @var PmsUnidadBeds24Map[] $maps */
        foreach ($maps as $map) {
            if ($uow !== null && $uow->isScheduledForDelete($map)) continue;

            foreach ($logicalRanges as $lr) {
                $sourceId = $lr->getSourceId();
                $isBaseWinner = is_string($sourceId) && str_starts_with($sourceId, 'base:');
                $winnerRango = $isBaseWinner ? null : ($sourceToRango[$sourceId] ?? null);

                $moneda = $isBaseWinner
                    ? $unidad->getTarifaBaseMoneda()
                    : ($winnerRango?->getMoneda() ?? $unidad->getTarifaBaseMoneda());

                $queue = $this->factory->create(
                    unidad: $unidad,
                    endpoint: $endpoint,
                    map: $map,
                    config: $map->getPmsUnidad()->getEstablecimiento()->getBeds24Config()
                );
                $queue->setFechaInicio($lr->getStart())
                    ->setFechaFin($lr->getEnd())
                    ->setPrecio(number_format($lr->getPrice(), 2, '.', ''))
                    ->setMinStay($lr->getMinStay())
                    ->setRunAt($now)
                    ->setMoneda($moneda);

                if ($winnerRango) {
                    $queue->setTarifaRango($winnerRango);
                }

                $this->em->persist($queue);
                if ($uow !== null) {
                    $uow->computeChangeSet($this->em->getClassMetadata(PmsRatesPushQueue::class), $queue);
                }
                $createdIds[] = (string)$queue->getId();
            }
        }

        return $createdIds;
    }

    // --- Helpers ---
    private function expandWindowIteratively(array $pendingQueues, DateTimeImmutable $from, DateTimeImmutable $to): array {
        $hasExpanded = true;
        $iterations = 0;
        while ($hasExpanded && $iterations < 50) {
            $hasExpanded = false;
            $iterations++;
            foreach ($pendingQueues as $q) {
                if ($q->getStatus() !== PmsRatesPushQueue::STATUS_PENDING) continue;
                $qStart = $this->toDay($q->getFechaInicio());
                $qEnd   = $this->toDay($q->getFechaFin());
                if ($qStart < $to && $qEnd > $from) {
                    if ($qStart < $from) { $from = $qStart; $hasExpanded = true; }
                    if ($qEnd > $to) { $to = $qEnd; $hasExpanded = true; }
                }
            }
        }
        return [$from, $to];
    }

    private function invalidateQueuesInWindow(array $queues, DateTimeImmutable $start, DateTimeImmutable $end, PmsUnidadBeds24Map $map, ?UnitOfWork $uow): void {
        foreach ($queues as $q) {
            if ($q->getUnidadBeds24Map() !== $map) continue;
            if ($q->getStatus() === PmsRatesPushQueue::STATUS_PROCESSING || $q->getStatus() === PmsRatesPushQueue::STATUS_SUCCESS) continue;
            $qStart = $this->toDay($q->getFechaInicio());
            $qEnd   = $this->toDay($q->getFechaFin());
            if ($qStart < $end && $qEnd > $start) {
                if ($this->em->contains($q)) {
                    $q->setStatus(PmsRatesPushQueue::STATUS_CANCELED);
                    $q->setFailedReason('Smart Flattening: Recálculo.');
                    if ($uow !== null) {
                        $uow->recomputeSingleEntityChangeSet($this->em->getClassMetadata(PmsRatesPushQueue::class), $q);
                    }
                }
            }
        }
    }

    private function getSourceIdForRango(PmsTarifaRango $r): string {
        return $r->getId() ? (string)$r->getId() : ('tmp:' . spl_object_id($r));
    }
    private function resolveEndpoint(): ?ExchangeEndpoint {
        return $this->em->getRepository(ExchangeEndpoint::class)->findOneBy([
            'provider' => ConnectivityProvider::BEDS24,
            'accion' => self::ACCION_ENDPOINT,
            'activo' => true
        ]);
    }
    private function fetchActiveMaps(PmsUnidad $u): array { return $this->em->getRepository(PmsUnidadBeds24Map::class)->findBy(['pmsUnidad' => $u, 'activo' => true]); }
    private function createRangeAccessor(): callable {
        return fn(PmsTarifaRango $r) => [
            'start' => $this->toDay($r->getFechaInicio()), 'end' => $this->toDay($r->getFechaFin()),
            'price' => $r->getPrecio(), 'minStay' => $r->getMinStay(),
            'currency' => $r->getMoneda()?->getId(), 'important' => $r->isImportante(),
            'weight' => $r->getPrioridad(), 'id' => $this->getSourceIdForRango($r)
        ];
    }
    private function createFallbackProvider(PmsUnidad $u): callable {
        return fn($d) => $u->isTarifaBaseActiva() ? [
            'price' => $u->getTarifaBasePrecio(), 'minStay' => $u->getTarifaBaseMinStay(),
            'currency' => $u->getTarifaBaseMonedaOrFail()->getId(), 'sourceId' => 'base:unidad:' . (string)$u->getId()
        ] : null;
    }
    private function toDay(DateTimeInterface $dt): DateTimeImmutable {
        return DateTimeImmutable::createFromInterface($dt)->setTime(0, 0, 0);
    }
}