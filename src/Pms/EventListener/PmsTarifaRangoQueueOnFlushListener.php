<?php
declare(strict_types=1);

namespace App\Pms\EventListener;

use App\Pms\Entity\PmsBeds24Endpoint;
use App\Pms\Entity\PmsTarifaQueue;
use App\Pms\Entity\PmsTarifaQueueDelivery;
use App\Pms\Entity\PmsTarifaRango;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Entity\PmsUnidadBeds24Map;
use App\Pms\Service\Tarifa\Engine\TarifaLogicalRangeCompressor;
use App\Pms\Service\Tarifa\Engine\TarifaPricingEngine;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;

#[AsDoctrineListener(event: Events::onFlush, priority: -700)]
final class PmsTarifaRangoQueueOnFlushListener
{
    /**
     * OJO: AJUSTA este accion a tu endpoint real en la tabla pms_beds24_endpoint.
     */
    private string $accionEndpointTarifa = 'CALENDAR_POST';

    public function __construct(
        private TarifaPricingEngine $pricingEngine,
        private TarifaLogicalRangeCompressor $compressor,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        if (!$em instanceof EntityManagerInterface) {
            return;
        }

        $uow = $em->getUnitOfWork();

        $this->handleScheduled($em, $uow, $uow->getScheduledEntityInsertions());
        $this->handleScheduled($em, $uow, $uow->getScheduledEntityUpdates());
        $this->handleScheduledDeletions($em, $uow, $uow->getScheduledEntityDeletions());
    }

    /**
     * @param array<int, object> $entities
     */
    private function handleScheduled(EntityManagerInterface $em, UnitOfWork $uow, array $entities): void
    {
        foreach ($entities as $entity) {
            if (!$entity instanceof PmsTarifaRango) {
                continue;
            }

            // Si aún está incompleto en el flush (admin), no hacemos nada.
            if ($entity->getUnidad() === null || $entity->getFechaInicio() === null || $entity->getFechaFin() === null) {
                continue;
            }

            $this->processTarifaRango($em, $uow, $entity, $uow->isScheduledForInsert($entity));
        }
    }

    private function processTarifaRango(
        EntityManagerInterface $em,
        UnitOfWork $uow,
        PmsTarifaRango $rango,
        bool $isInsert
    ): void {
        $changeSet = $uow->getEntityChangeSet($rango);

        $newUnidad = $rango->getUnidad();
        $newInicio = $rango->getFechaInicio();
        $newFin    = $rango->getFechaFin();

        if ($newUnidad === null || $newInicio === null || $newFin === null) {
            return;
        }

        // Valores previos (solo relevantes en UPDATE)
        $oldUnidad = $this->getOldValue($changeSet, 'unidad');
        $oldInicio = $this->getOldValue($changeSet, 'fechaInicio');
        $oldFin    = $this->getOldValue($changeSet, 'fechaFin');

        $oldUnidadObj = $oldUnidad instanceof PmsUnidad ? $oldUnidad : null;
        $oldInicioObj = $oldInicio instanceof DateTimeInterface ? $oldInicio : null;
        $oldFinObj    = $oldFin instanceof DateTimeInterface ? $oldFin : null;

        // ------------------------------------------------------------------
        // SOFT DELETE: activo true -> false  => tratar igual que DELETE
        // (en onFlush, DB aún devuelve activo=true, por eso hay que excluirlo)
        // ------------------------------------------------------------------
        $oldActivo = $this->getOldValue($changeSet, 'activo');
        $newActivo = (bool) ($rango->isActivo() ?? false);

        $isSoftDelete = ($oldActivo === true) && ($newActivo === false);

        if ($isSoftDelete) {
            // Recalcular la unidad/fechas "anteriores" (o fallback a las actuales si faltan)
            $unidadSoft = $oldUnidadObj ?? $newUnidad;
            $inicioSoft = $oldInicioObj ?? $newInicio;
            $finSoft    = $oldFinObj ?? $newFin;

            $fromDay = $this->toDay($inicioSoft);
            $toEx    = $this->toDay($finSoft);

            if ($toEx > $fromDay) {
                $this->buildQueuesForUnit(
                    $em,
                    $uow,
                    $rango,
                    $unidadSoft,
                    $fromDay,
                    $toEx,
                    $changeSet,
                    $isInsert,
                    true // isDelete=true
                );
            }

            return;
        }

        // ------------------------------------------------------------------
        // Caso especial: la tarifa se movió de unidad.
        // Recalculamos intervalos separados:
        // - oldUnidad con [oldInicio, oldFin) como "DELETE" en unidad vieja
        // - newUnidad con [newInicio, newFin) como normal en unidad nueva
        // ------------------------------------------------------------------
        if ($oldUnidadObj instanceof PmsUnidad && $oldUnidadObj !== $newUnidad) {
            // 1) Unidad vieja (como DELETE)
            if ($oldInicioObj !== null && $oldFinObj !== null) {
                $fromDayOld = $this->toDay($oldInicioObj);
                $toExOld    = $this->toDay($oldFinObj);

                if ($toExOld > $fromDayOld) {
                    $this->buildQueuesForUnit(
                        $em,
                        $uow,
                        $rango,
                        $oldUnidadObj,
                        $fromDayOld,
                        $toExOld,
                        $changeSet,
                        $isInsert,
                        true // isDelete=true
                    );
                }
            } else {
                // Fallback defensivo: si no hay old fechas, recalculamos el nuevo intervalo también en la unidad vieja como DELETE
                $fromDayFallback = $this->toDay($newInicio);
                $toExFallback    = $this->toDay($newFin);

                if ($toExFallback > $fromDayFallback) {
                    $this->buildQueuesForUnit(
                        $em,
                        $uow,
                        $rango,
                        $oldUnidadObj,
                        $fromDayFallback,
                        $toExFallback,
                        $changeSet,
                        $isInsert,
                        true // isDelete=true
                    );
                }
            }

            // 2) Unidad nueva (normal)
            $fromDayNew = $this->toDay($newInicio);
            $toExNew    = $this->toDay($newFin);

            if ($toExNew > $fromDayNew) {
                $this->buildQueuesForUnit(
                    $em,
                    $uow,
                    $rango,
                    $newUnidad,
                    $fromDayNew,
                    $toExNew,
                    $changeSet,
                    $isInsert,
                    false
                );
            }

            return;
        }

        // ------------------------------------------------------------------
        // Caso normal: misma unidad (o INSERT). Recalculamos un superset seguro.
        // ------------------------------------------------------------------
        $from = $this->minDate($oldInicioObj, $newInicio);
        $to   = $this->maxDate($oldFinObj, $newFin);

        $fromDay = $this->toDay($from);
        $toExclusive = $this->toDay($to);

        if ($toExclusive <= $fromDay) {
            return;
        }

        $this->buildQueuesForUnit(
            $em,
            $uow,
            $rango,
            $newUnidad,
            $fromDay,
            $toExclusive,
            $changeSet,
            $isInsert,
            false
        );
    }

    /**
     * @param array<int, object> $entities
     */
    private function handleScheduledDeletions(EntityManagerInterface $em, UnitOfWork $uow, array $entities): void
    {
        foreach ($entities as $entity) {
            if (!$entity instanceof PmsTarifaRango) {
                continue;
            }

            // En DELETE no dependemos del estado actual (puede estar incompleto),
            // usamos el changeset si existe, y si no, usamos getters como fallback.
            $this->processTarifaRangoDeletion($em, $uow, $entity);
        }
    }

    private function processTarifaRangoDeletion(EntityManagerInterface $em, UnitOfWork $uow, PmsTarifaRango $rango): void
    {
        $changeSet = $uow->getEntityChangeSet($rango);

        $oldUnidad = $this->getOldValue($changeSet, 'unidad');
        $oldInicio = $this->getOldValue($changeSet, 'fechaInicio');
        $oldFin    = $this->getOldValue($changeSet, 'fechaFin');

        $unidad = $oldUnidad instanceof PmsUnidad ? $oldUnidad : $rango->getUnidad();
        $inicio = $oldInicio instanceof DateTimeInterface ? $oldInicio : $rango->getFechaInicio();
        $fin    = $oldFin instanceof DateTimeInterface ? $oldFin : $rango->getFechaFin();

        if (!$unidad instanceof PmsUnidad || !$inicio instanceof DateTimeInterface || !$fin instanceof DateTimeInterface) {
            return;
        }

        $fromDay = $this->toDay($inicio);
        $toExclusive = $this->toDay($fin);

        if ($toExclusive <= $fromDay) {
            return;
        }

        // En DELETE: recalculamos el estado resultante (sin incluir el rango borrado).
        $this->buildQueuesForUnit(
            $em,
            $uow,
            $rango,
            $unidad,
            $fromDay,
            $toExclusive,
            $changeSet,
            false,
            true
        );
    }

    private function buildQueuesForUnit(
        EntityManagerInterface $em,
        UnitOfWork $uow,
        PmsTarifaRango $rangoTouched,
        PmsUnidad $unidad,
        DateTimeImmutable $fromDay,
        DateTimeImmutable $toExclusive,
        array $changeSet,
        bool $isInsert,
        bool $isDelete = false
    ): void {
        // 1) Trae rangos activos que intersecten con [fromDay, toExclusive)
        // Intersección (half-open):
        //   start < toExclusive AND end > fromDay
        $rangos = $em->createQuery(
            'SELECT r
             FROM App\Pms\Entity\PmsTarifaRango r
             WHERE r.unidad = :unidad
               AND r.activo = true
               AND r.fechaInicio < :toExclusive
               AND r.fechaFin > :fromDay'
        )
            ->setParameter('unidad', $unidad)
            ->setParameter('fromDay', $fromDay)
            ->setParameter('toExclusive', $toExclusive)
            ->getResult();

        // DELETE (o move de unidad visto como DELETE en la unidad vieja):
        // Durante onFlush, el UPDATE/DELETE aún no pegó en DB, así que el query puede devolver el rango tocado.
        // Lo excluimos explícitamente para recalcular el estado resultante (fallback/base, etc.).
        if ($isDelete) {
            $rangos = array_values(array_filter(
                $rangos,
                static fn($rr) => $rr !== $rangoTouched
            ));
        }

        // IMPORTANTE (onFlush):
        // En INSERT el rango tocado aún no existe en DB, por lo que la query no lo retorna.
        // También en algunos escenarios el EM puede no devolverlo por caché/identidad.
        // Si el rango tocado pertenece a esta unidad, está activo, e intersecta el intervalo, lo incluimos.
        if (!$isDelete && $rangoTouched->getUnidad() === $unidad && ($rangoTouched->isActivo() ?? false)) {
            $rtStart = $rangoTouched->getFechaInicio();
            $rtEndExcl = $rangoTouched->getFechaFin();

            if ($rtStart !== null && $rtEndExcl !== null) {
                $rtStartDay = $this->toDay($rtStart);
                $rtEndDayExcl = $this->toDay($rtEndExcl);

                // Intersecta [fromDay, toExclusive)
                if ($rtEndDayExcl > $fromDay && $rtStartDay < $toExclusive) {
                    $already = false;
                    foreach ($rangos as $rr) {
                        if ($rr === $rangoTouched) {
                            $already = true;
                            break;
                        }
                    }
                    if (!$already) {
                        $rangos[] = $rangoTouched;
                    }
                }
            }
        }

        // IMPORTANTE:
        // NO retornamos si no hay rangos.
        // Si una unidad se queda sin rangos (por DELETE o MOVE), igual debemos empujar
        // la tarifa base (fallback) o el “vacío” según la configuración de la unidad.
        // El engine debe poder producir rangos solo con fallbackProvider.

        // 2) Índice sourceId => rango (para mapear ganador real)
        $sourceToRango = [];
        foreach ($rangos as $rr) {
            if (!$rr instanceof PmsTarifaRango) {
                continue;
            }
            $sourceToRango[$this->expectedSourceIdForRango($rr)] = $rr;
        }

        // 3) Accessor “AFTER state” para engine/flattener (END EXCLUSIVO tal cual BD)
        $accessorAfter = function (object $r): array {
            /** @var PmsTarifaRango $r */
            $start = $r->getFechaInicio();
            $endExclusive = $r->getFechaFin();

            if ($start === null || $endExclusive === null) {
                return [];
            }

            // Id estable para sourceId:
            // - si hay id real => int
            // - si no => 'tmp:<spl_object_id>' para que sourceId sea estable en memoria durante el flush
            $id = $r->getId();
            $tmpId = $id !== null ? (int) $id : ('tmp:' . spl_object_id($r));

            $currency = null;
            if ($r->getMoneda() !== null && method_exists($r->getMoneda(), 'getCodigo')) {
                $currency = (string) $r->getMoneda()->getCodigo();
            }

            return [
                'start' => $this->toDay($start),
                'end' => $this->toDay($endExclusive),
                'price' => $r->getPrecio() ?? '0',
                'minStay' => $r->getMinStay() ?? 2,
                'currency' => $currency,
                'important' => (bool) ($r->isImportante() ?? false),
                'weight' => (int) ($r->getPeso() ?? 0),
                'id' => $tmpId,
            ];
        };

        // 4) Fallback provider: tarifa base de la unidad (solo si está activa).
        // OJO: también es day-exclusive (solo rellena días sueltos).
        $fallbackProvider = function (DateTimeImmutable $day) use ($unidad): ?array {
            $isActive = (bool) ($unidad->isTarifaBaseActiva() ?? false);
            $precioBaseRaw = $unidad->getTarifaBasePrecio();

            if (!$isActive || $precioBaseRaw === null || $precioBaseRaw === '') {
                return null;
            }

            $minStayBase = (int) ($unidad->getTarifaBaseMinStay() ?? 2);
            if ($minStayBase <= 0) {
                $minStayBase = 2;
            }

            $currency = null;
            $moneda = $unidad->getTarifaBaseMoneda();
            if ($moneda !== null && method_exists($moneda, 'getCodigo')) {
                $currency = (string) $moneda->getCodigo();
            }

            return [
                'price' => $precioBaseRaw,
                'minStay' => $minStayBase,
                'currency' => $currency,
                'sourceId' => 'base:unidad:' . (string) ($unidad->getId() ?? '0'),
            ];
        };

        // 5) Generar rangos lógicos efectivos para TODO el intervalo [fromDay, toExclusive),
        // rellenando huecos con fallback (tarifa base unidad).
        $rangesToPush = $this->pricingEngine->buildLogicalRangesForIntervalWithFallback(
            $rangos,
            $fromDay,
            $toExclusive,
            $accessorAfter,
            null,
            $fallbackProvider
        );

        if ($rangesToPush === []) {
            return;
        }

        // 6) Endpoint para tarifa
        $endpoint = $this->resolveEndpoint($em, $this->accionEndpointTarifa);
        if ($endpoint === null) {
            return;
        }

        // 7) Maps activos (1 delivery por map)
        $maps = $em->createQuery(
            'SELECT m
             FROM App\Pms\Entity\PmsUnidadBeds24Map m
             WHERE m.pmsUnidad = :unidad
               AND m.activo = true'
        )
            ->setParameter('unidad', $unidad)
            ->getResult();

        // 8) Crear/merge queues y fan-out deliveries.
        $newQueues = [];

        foreach ($rangesToPush as $lr) {
            $qStart = $lr->getStart();
            $qEndExclusive = $lr->getEnd(); // EXCLUSIVO (no tocar)

            $winnerSourceId = $lr->getSourceId();

            // Mapeo ganador:
            // - Si el winner es la tarifa base (fallback), NO hay PmsTarifaRango ganador.
            // - Si no es base, resolvemos contra el índice; y solo como último fallback usamos $rangoTouched.
            $isBaseWinner = is_string($winnerSourceId)
                && $winnerSourceId !== ''
                && str_starts_with($winnerSourceId, 'base:unidad:');

            $winnerRango = null;
            if (!$isBaseWinner) {
                $winnerRango = ($winnerSourceId !== null && isset($sourceToRango[(string) $winnerSourceId]))
                    ? $sourceToRango[(string) $winnerSourceId]
                    : $rangoTouched;
            }

            // Moneda del ganador:
            $winnerMoneda = $isBaseWinner
                ? $unidad->getTarifaBaseMoneda()
                : ($winnerRango?->getMoneda());

            $queue = null;
            if ($winnerRango instanceof PmsTarifaRango) {
                $queue = $this->findMergeableQueue(
                    $em,
                    $winnerRango,
                    $unidad,
                    $endpoint,
                    $qStart,
                    $qEndExclusive
                );
            }

            // Merge en memoria con colas creadas en este mismo flush
            if ($queue === null) {
                $qStartDay = $this->toDay($qStart);
                $qEndDay = $this->toDay($qEndExclusive);

                foreach ($newQueues as $candidate) {
                    if (!$candidate instanceof PmsTarifaQueue) {
                        continue;
                    }

                    // mismo rango:
                    // - Si winnerRango es NULL (base), el candidato también debe tener tarifaRango NULL.
                    // - Si hay rango, y no hay id aún, comparamos instancia.
                    if ($winnerRango === null) {
                        if ($candidate->getTarifaRango() !== null) {
                            continue;
                        }
                    } else {
                        if ($candidate->getTarifaRango()?->getId() !== $winnerRango->getId()) {
                            if ($candidate->getTarifaRango() !== $winnerRango) {
                                continue;
                            }
                        }
                    }

                    if ($candidate->getUnidad()?->getId() !== $unidad->getId()) {
                        continue;
                    }
                    if ($candidate->getEndpoint()?->getId() !== $endpoint->getId()) {
                        continue;
                    }
                    if ($candidate->getStatus() !== PmsTarifaQueue::STATUS_PENDING) {
                        continue;
                    }

                    $cStart = $candidate->getFechaInicio();
                    $cEnd = $candidate->getFechaFin();
                    if ($cStart === null || $cEnd === null) {
                        continue;
                    }

                    $cStartDay = $this->toDay($cStart);
                    $cEndDay = $this->toDay($cEnd);

                    // Merge si solapa o es contiguo en convención [start,end)
                    // Contiguo si cEnd == qStart o qEnd == cStart
                    $overlap = ($cStartDay < $qEndDay) && ($cEndDay > $qStartDay);
                    $touch = ($cEndDay == $qStartDay) || ($qEndDay == $cStartDay);

                    if ($overlap || $touch) {
                        $queue = $candidate;
                        break;
                    }
                }
            }

            // Misma marca temporal para el queue y TODOS sus deliveries (para orden robusto del worker)
            $effectiveAt = new DateTimeImmutable();

            if ($queue === null) {
                $queue = (new PmsTarifaQueue());

                if ($winnerRango instanceof PmsTarifaRango) {
                    $queue->setTarifaRango($winnerRango);
                }

                $queue
                    ->setUnidad($unidad)
                    ->setEndpoint($endpoint)
                    ->setFechaInicio($qStart)
                    ->setFechaFin($qEndExclusive) // EXCLUSIVO
                    ->setPrecio(number_format($lr->getPrice(), 2, '.', ''))
                    ->setMinStay($lr->getMinStay())
                    ->setMoneda($winnerMoneda)
                    ->setNeedsSync(true)
                    ->setStatus(PmsTarifaQueue::STATUS_PENDING)
                    ->setEffectiveAt($effectiveAt);

                $em->persist($queue);
                $uow->computeChangeSet($em->getClassMetadata(PmsTarifaQueue::class), $queue);

                $newQueues[] = $queue;
            } else {
                // extender (merge) y refrescar datos
                $min = $this->minDate($queue->getFechaInicio(), $qStart);
                $max = $this->maxDate($queue->getFechaFin(), $qEndExclusive);

                $queue
                    ->setFechaInicio($min)
                    ->setFechaFin($max)
                    ->setPrecio(number_format($lr->getPrice(), 2, '.', ''))
                    ->setMinStay($lr->getMinStay())
                    ->setMoneda($winnerMoneda)
                    ->setNeedsSync(true)
                    ->setStatus(PmsTarifaQueue::STATUS_PENDING)
                    ->setEffectiveAt($effectiveAt);

                if ($winnerRango instanceof PmsTarifaRango) {
                    if ($queue->getTarifaRango() !== $winnerRango) {
                        $queue->setTarifaRango($winnerRango);
                    }
                }
            }

            // Fan-out deliveries: 1 por map activo
            foreach ($maps as $map) {
                if (!$map instanceof PmsUnidadBeds24Map) {
                    continue;
                }

                $delivery = null;
                foreach ($queue->getDeliveries() as $d) {
                    if ($d instanceof PmsTarifaQueueDelivery && $d->getUnidadBeds24Map()?->getId() === $map->getId()) {
                        $delivery = $d;
                        break;
                    }
                }

                if ($delivery !== null) {
                    $delivery->setEffectiveAt($queue->getEffectiveAt());

                    $deliveryMeta = $em->getClassMetadata(PmsTarifaQueueDelivery::class);

                    if ($uow->isScheduledForInsert($delivery) || $uow->getEntityState($delivery) === UnitOfWork::STATE_NEW) {
                        if ($uow->getEntityChangeSet($delivery) === []) {
                            $uow->computeChangeSet($deliveryMeta, $delivery);
                        }
                    } else {
                        $uow->recomputeSingleEntityChangeSet($deliveryMeta, $delivery);
                    }

                    continue;
                }

                $delivery = (new PmsTarifaQueueDelivery())
                    ->setQueue($queue)
                    ->setUnidadBeds24Map($map)
                    ->setNeedsSync(true)
                    ->setStatus(PmsTarifaQueueDelivery::STATUS_PENDING)
                    ->setEffectiveAt($queue->getEffectiveAt());

                $queue->addDelivery($delivery);

                $em->persist($delivery);
                $uow->computeChangeSet($em->getClassMetadata(PmsTarifaQueueDelivery::class), $delivery);
            }

            // Recompute/compute para queue según estado (anti-HY093)
            $queueMeta = $em->getClassMetadata(PmsTarifaQueue::class);
            if ($uow->isScheduledForInsert($queue) || $uow->getEntityState($queue) === UnitOfWork::STATE_NEW) {
                if ($uow->getEntityChangeSet($queue) === []) {
                    $uow->computeChangeSet($queueMeta, $queue);
                }
            } else {
                $uow->recomputeSingleEntityChangeSet($queueMeta, $queue);
            }
        }
    }

    private function findMergeableQueue(
        EntityManagerInterface $em,
        PmsTarifaRango $rango,
        PmsUnidad $unidad,
        PmsBeds24Endpoint $endpoint,
        DateTimeInterface $start,
        DateTimeInterface $endExclusive
    ): ?PmsTarifaQueue {
        // onFlush: si el rango todavía no tiene ID (INSERT), no podemos bindearlo en DQL.
        // El merge en memoria (newQueues) cubre este caso.
        if ($rango->getId() === null) {
            return null;
        }
        // Merge SOLO si:
        // - mismo tarifaRango
        // - status pending
        // - solapa o es contiguo bajo convención [start,end)
        $startDay = $this->toDay($start);
        $endDay = $this->toDay($endExclusive);

        // Rango expandido para permitir contigüidad (touch):
        // buscamos queues que intersecten con [start-1, end+1)
        $from = $startDay->modify('-1 day');
        $to = $endDay->modify('+1 day');

        /** @var PmsTarifaQueue|null $q */
        return $em->createQuery(
            'SELECT q
             FROM App\Pms\Entity\PmsTarifaQueue q
             WHERE q.tarifaRango = :rango
               AND q.unidad = :unidad
               AND q.endpoint = :endpoint
               AND q.status = :status
               AND q.fechaInicio < :toExclusive
               AND q.fechaFin > :fromDay
             ORDER BY q.id DESC'
        )
            ->setMaxResults(1)
            ->setParameter('rango', $rango)
            ->setParameter('unidad', $unidad)
            ->setParameter('endpoint', $endpoint)
            ->setParameter('status', PmsTarifaQueue::STATUS_PENDING)
            ->setParameter('fromDay', $from)
            ->setParameter('toExclusive', $to)
            ->getOneOrNullResult();
    }

    private function resolveEndpoint(EntityManagerInterface $em, string $accion): ?PmsBeds24Endpoint
    {
        /** @var PmsBeds24Endpoint|null $ep */
        return $em->createQuery(
            'SELECT e
             FROM App\Pms\Entity\PmsBeds24Endpoint e
             WHERE e.accion = :accion
               AND e.activo = true'
        )
            ->setMaxResults(1)
            ->setParameter('accion', $accion)
            ->getOneOrNullResult();
    }

    private function expectedSourceIdForRango(PmsTarifaRango $r): string
    {
        $id = $r->getId();
        if ($id !== null) {
            return 'id:' . (string) $id;
        }

        return 'id:tmp:' . spl_object_id($r);
    }

    private function toDay(DateTimeInterface $dt): DateTimeImmutable
    {
        $imm = ($dt instanceof DateTimeImmutable) ? $dt : DateTimeImmutable::createFromInterface($dt);
        return $imm->setTime(0, 0, 0);
    }

    private function minDate(?DateTimeInterface $a, DateTimeInterface $b): DateTimeImmutable
    {
        $bb = $this->toDay($b);
        if ($a === null) {
            return $bb;
        }

        $aa = $this->toDay($a);
        return ($aa <= $bb) ? $aa : $bb;
    }

    private function maxDate(?DateTimeInterface $a, DateTimeInterface $b): DateTimeImmutable
    {
        $bb = $this->toDay($b);
        if ($a === null) {
            return $bb;
        }

        $aa = $this->toDay($a);
        return ($aa >= $bb) ? $aa : $bb;
    }

    /**
     * @param array<string, array{0:mixed,1:mixed}> $changeSet
     */
    private function getOldValue(array $changeSet, string $field): mixed
    {
        return $changeSet[$field][0] ?? null;
    }
}