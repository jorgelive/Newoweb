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

/**
 * Servicio Beds24RatesPushQueueCreator.
 * Orquesta la invalidación de colas obsoletas y la creación de nuevos intervalos aplanados.
 * ✅ Soporta UUID v7 y llaves naturales para monedas/estados.
 *
 * ✅ BLINDAJE "NO HUECOS":
 * - Si cancelamos colas PENDING que cubren más rango que el intervalo sucio,
 *   expandimos el intervalo de recálculo a la unión de lo cancelado.
 * - Así nunca queda “rango sin tarifa” por haber cancelado una cola grande
 *   y re-encolado solo una ventana pequeña.
 */
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

    /**
     * Encola un intervalo de tiempo para sincronización, resolviendo solapamientos.
     * 1. Invalida colas PENDING existentes en el rango.
     * 2. Calcula el aplanamiento (Flattening) mediante el Pricing Engine.
     * 3. Crea registros limpios en la cola de empuje.
     */
    public function enqueueForInterval(
        PmsUnidad $unidad,
        DateTimeInterface $start,
        DateTimeInterface $end,
        ?PmsTarifaRango $dirtyRango = null,
        bool $isDelete = false,
        ?UnitOfWork $uow = null
    ): void {
        // Bloqueo de seguridad si ya estamos en un proceso de Push
        if ($this->syncContext->isPush()) {
            return;
        }

        $fromDay = $this->toDay($start);
        $toExclusive = $this->toDay($end);

        if ($toExclusive <= $fromDay) {
            return;
        }

        // 1) Resolución de infraestructura técnica
        $endpoint = $this->resolveEndpoint();
        $maps = $this->fetchActiveMaps($unidad);
        if (!$endpoint || empty($maps)) {
            return;
        }

        // 2) Carga de colas pendientes para limpieza (solo dentro del intervalo solicitado)
        $pendingQueues = $this->repo->findPendingForUnit(
            (string) $unidad->getId(),
            $fromDay,
            $toExclusive
        );

        // ✅ 3) BLINDAJE: expandimos ventana si existen colas grandes solapadas
        // Esto evita “huecos” cuando cancelas una cola grande y solo reencolas una ventana chica.
        [$recalcFrom, $recalcTo] = $this->expandWindowByPendingQueues($pendingQueues, $fromDay, $toExclusive);

        if ($recalcTo <= $recalcFrom) {
            return;
        }

        // 4) Obtención y preparación de rangos (Memoria + DB) usando la ventana expandida
        $rangos = $this->fetchDbRangos($unidad, $recalcFrom, $recalcTo);

        if ($dirtyRango !== null) {
            if ($isDelete) {
                $rangos = array_values(array_filter($rangos, fn($r) => $r !== $dirtyRango));
            } elseif ($dirtyRango->getUnidad() === $unidad && ($dirtyRango->isActivo() ?? false)) {
                if (!in_array($dirtyRango, $rangos, true)) {
                    $rangos[] = $dirtyRango;
                }
            }
        }

        $sourceToRango = [];
        foreach ($rangos as $rr) {
            $sourceToRango[$this->expectedSourceIdForRango($rr)] = $rr;
        }

        // 5) Cálculo lógico de intervalos (Aplanado) usando la ventana expandida
        $logicalRanges = $this->pricingEngine->buildLogicalRangesForIntervalWithFallback(
            $rangos,
            $recalcFrom,
            $recalcTo,
            $this->createRangeAccessor(),
            null,
            $this->createFallbackProvider($unidad)
        );

        if (empty($logicalRanges)) {
            return;
        }

        // 6) Invalidación dentro de la ventana expandida
        $now = new DateTimeImmutable();

        foreach ($maps as $map) {
            // Protección contra borrados en cascada del mapa
            if ($uow !== null && $uow->isScheduledForDelete($map)) {
                continue;
            }

            // 🔥 INVALIDACIÓN: Limpiamos lo pendiente antes de insertar lo nuevo
            $includeFailed = !$this->syncContext->isPull();  // UI / reaplanado fuerte
            $this->invalidatePendingQueues(pendingQueues: $pendingQueues, start: $fromDay, end: $toExclusive, map: $map, uow: $uow, includeFailed: $includeFailed);

            // 7) CREACIÓN: Encolamos cobertura completa (sin huecos) del rango expandido
            foreach ($logicalRanges as $lr) {
                $winnerSourceId = $lr->getSourceId();
                $isBaseWinner = is_string($winnerSourceId) && str_starts_with($winnerSourceId, 'base:');

                $fallback = $isDelete ? null : $dirtyRango;
                $winnerRango = $isBaseWinner ? null : ($sourceToRango[$winnerSourceId] ?? $fallback);

                // Resolución de moneda (Llave natural)
                $moneda = $isBaseWinner ? $unidad->getTarifaBaseMoneda() : $winnerRango?->getMoneda();

                $queue = new PmsRatesPushQueue();
                $queue
                    ->setUnidad($unidad)
                    ->setUnidadBeds24Map($map)
                    ->setEndpoint($endpoint)
                    ->setFechaInicio($lr->getStart())
                    ->setFechaFin($lr->getEnd())
                    ->setPrecio(number_format($lr->getPrice(), 2, '.', ''))
                    ->setMinStay($lr->getMinStay())
                    ->setMoneda($moneda)
                    ->setStatus(PmsRatesPushQueue::STATUS_PENDING)
                    ->setRunAt($now)
                    ->setEffectiveAt($now);

                if ($winnerRango) {
                    $queue->setTarifaRango($winnerRango);
                }

                $this->em->persist($queue);

                if ($uow !== null) {
                    $uow->computeChangeSet($this->em->getClassMetadata(PmsRatesPushQueue::class), $queue);
                }
            }
        }
    }

    // =========================================================================
    //  BLINDAJE ANTI-HUECOS
    // =========================================================================

    /**
     * Si existen colas PENDING solapadas que cubren más rango que el intervalo solicitado,
     * expandimos el recálculo a la UNIÓN.
     *
     * @param array<int, PmsRatesPushQueue> $pendingQueues
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable} [$newFrom, $newToExclusive]
     */
    private function expandWindowByPendingQueues(array $pendingQueues, DateTimeImmutable $fromDay, DateTimeImmutable $toExclusive): array
    {
        $newFrom = $fromDay;
        $newTo   = $toExclusive;

        foreach ($pendingQueues as $q) {
            if (!$q instanceof PmsRatesPushQueue) {
                continue;
            }
            if ($q->getStatus() !== PmsRatesPushQueue::STATUS_PENDING) {
                continue;
            }

            $qStart = $this->toDay($q->getFechaInicio());
            $qEnd   = $this->toDay($q->getFechaFin());

            // overlap: qStart < to AND qEnd > from
            if ($qStart < $newTo && $qEnd > $newFrom) {
                if ($qStart < $newFrom) {
                    $newFrom = $qStart;
                }
                if ($qEnd > $newTo) {
                    $newTo = $qEnd;
                }
            }
        }

        return [$newFrom, $newTo];
    }

    // =========================================================================
    //  RESOLVERS & HELPERS (tus métodos existentes)
    // =========================================================================

    /**
     * Cancela colas pendientes solapadas para evitar datos contradictorios en Beds24.
     */
    private function invalidatePendingQueues(
        array $pendingQueues,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        PmsUnidadBeds24Map $map,
        ?UnitOfWork $uow,
        bool $includeFailed = false // ✅ solo true si es UI/edit/reaplanado fuerte
    ): void {
        foreach ($pendingQueues as $q) {
            if (!$q instanceof PmsRatesPushQueue) {
                continue;
            }

            // 0) Match por map
            if ($q->getUnidadBeds24Map() !== $map) {
                continue;
            }

            // 1) Nunca tocar lo que ya está aplicado o ejecutándose
            $status = $q->getStatus();

            if ($status === PmsRatesPushQueue::STATUS_PROCESSING) {
                continue; // worker en curso: intocable
            }

            if ($status === PmsRatesPushQueue::STATUS_SUCCESS) {
                continue; // ya aplicado/auditado: intocable
            }

            // 2) Solo invalidamos lo "reprocesable"
            $isReprocessable = ($status === PmsRatesPushQueue::STATUS_PENDING)
                || ($includeFailed && $status === PmsRatesPushQueue::STATUS_FAILED);

            if (!$isReprocessable) {
                continue;
            }

            // 3) Solape temporal [qStart,qEnd) con [start,end)
            $qStart = $this->toDay($q->getFechaInicio());
            $qEnd   = $this->toDay($q->getFechaFin());

            if (!($qStart < $end && $qEnd > $start)) {
                continue;
            }

            // 4) Blindaje: si doctrine no lo está gestionando, NO recompute (evita el 500)
            // Igual podemos setear el status, pero si no está managed no lo persistirá.
            // Mejor: saltar y listo (o recargar por ID en el repo antes de este método).
            if ($this->em->contains($q) === false) {
                continue;
            }

            // 5) Invalidate
            $q->setStatus(PmsRatesPushQueue::STATUS_CANCELED);
            $q->setFailedReason($includeFailed
                ? 'Invalidada por re-aplanado (reemplazo de pending/failed).'
                : 'Invalidada por re-aplanado (reemplazo de pending).'
            );

            if ($uow !== null) {
                // Si la entidad ya estaba programada para update en este flush, recompute está OK.
                $uow->recomputeSingleEntityChangeSet(
                    $this->em->getClassMetadata(PmsRatesPushQueue::class),
                    $q
                );
            }
        }
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

    private function fetchDbRangos(PmsUnidad $u, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        // Tu repo actual no filtra por unidad internamente; si lo hace, perfecto.
        // Si NO lo hace, considera pasar la unidad y filtrar en el repositorio.
        return $this->em->getRepository(PmsTarifaRango::class)
            ->findOverlappingForInterval($from, $to);
    }

    private function expectedSourceIdForRango(PmsTarifaRango $r): string
    {
        return $r->getId() ? 'id:' . (string) $r->getId() : 'id:tmp:' . spl_object_id($r);
    }

    private function createRangeAccessor(): callable
    {
        return fn(PmsTarifaRango $r) => [
            'start' => $this->toDay($r->getFechaInicio()),
            'end'   => $this->toDay($r->getFechaFin()),
            'price' => $r->getPrecio(),
            'minStay' => $r->getMinStay(),
            'currency' => $r->getMoneda()?->getId(),
            'important' => $r->isImportante(),
            'weight' => $r->getPrioridad(),
            'id' => $r->getId() ? (string) $r->getId() : ('tmp:' . spl_object_id($r))
        ];
    }

    private function createFallbackProvider(PmsUnidad $u): callable
    {
        return fn($d) => $u->isTarifaBaseActiva() ? [
            'price' => $u->getTarifaBasePrecio(),
            'minStay' => $u->getTarifaBaseMinStay(),
            'currency' => $u->getTarifaBaseMonedaOrFail()->getId(),
            'sourceId' => 'base:unidad:' . (string) $u->getId()
        ] : null;
    }

    private function toDay(DateTimeInterface $dt): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($dt)->setTime(0, 0, 0);
    }
}