<?php

declare(strict_types=1);

namespace App\Pms\Service\Beds24\Queue;

use App\Exchange\Service\Context\SyncContext;
use App\Pms\Entity\PmsBeds24Endpoint;
use App\Pms\Entity\PmsBookingsPushQueue;
use App\Pms\Entity\PmsEventoBeds24Link;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork;

/**
 * Servicio Beds24BookingsPushQueueCreator.
 * * Gestiona el encolado de sincronizaciones hacia Beds24 con lógica de deduplicación
 * e integridad para movimientos de habitación y limpieza de bloqueos (mirrors).
 */
final class Beds24BookingsPushQueueCreator
{
    private const ENDPOINT_POST_BOOKINGS = 'POST_BOOKINGS';
    private const ENDPOINT_DELETE_BOOKINGS = 'DELETE_BOOKINGS'; // Agregada constante para claridad

    /**
     * Cache en memoria para evitar duplicados en el mismo ciclo de flush.
     * @var array<string, PmsBookingsPushQueue>
     */
    private array $runtimeDedupe = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SyncContext $syncContext,
    ) {}

    public function enqueueForLink(
        PmsEventoBeds24Link $link,
        PmsBeds24Endpoint $endpoint,
        ?UnitOfWork $uow = null
    ): void {
        // 0. Si el worker PUSH ya está procesando, evitamos bucles infinitos.
        if ($this->syncContext->isPush()) {
            return;
        }

        // Detectar si el link está marcado para borrado físico.
        $isDelete = ($uow !== null && $uow->isScheduledForDelete(entity: $link));

        // -----------------------------------------------------------
        // 1. 🛡️ ESCUDO DE SEGURIDAD PARA PULL (Webhooks)
        // -----------------------------------------------------------
        if ($this->syncContext->isPull()) {
            if ($link->isEsPrincipal()) {
                return;
            }
        }

        // 2. Validaciones de integridad básica.
        $evento = $link->getEvento();
        if (!$evento && !$isDelete) {
            return;
        }

        $map = $link->getUnidadBeds24Map();
        if ($map === null) {
            return;
        }

        // 3. Clave de deduplicación basada en UUID v7 (estable antes del flush).
        $linkId = (string) $link->getId();
        $dedupeKey = sprintf('link:%s:endpoint:%s', $linkId, $endpoint->getAccion());

        // 4. Snapshot de datos
        $payload = [
            'linkId'       => $linkId,
            'isMirror'     => !$link->isEsPrincipal(),
            'eventoId'     => $evento?->getId() ? (string) $evento->getId() : null,
            'inicio'       => $evento?->getInicio()?->format('c'),
            'fin'          => $evento?->getFin()?->format('c'),
            'estado'       => $evento?->getEstado()?->getId(),
            'beds24RoomId' => $map->getBeds24RoomId(),
            'configId'     => $map->getBeds24Config()?->getId() ? (string) $map->getBeds24Config()->getId() : null,
        ];

        $json = json_encode(value: $payload, flags: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $payloadHash = sha1((string) $json);

        // 5. Gestión de tareas existentes (Idempotencia).
        if (!$isDelete) {
            $existingQueue = $this->findExistingQueue(link: $link, dedupeKey: $dedupeKey, uow: $uow);

            if ($existingQueue instanceof PmsBookingsPushQueue) {
                if ($existingQueue->getPayloadHash() === $payloadHash
                    && $existingQueue->getStatus() === PmsBookingsPushQueue::STATUS_PENDING
                ) {
                    return;
                }

                $existingQueue
                    ->setBeds24Config($map->getBeds24Config())
                    ->setEndpoint($endpoint)
                    ->setPayloadHash($payloadHash)
                    ->setStatus(PmsBookingsPushQueue::STATUS_PENDING)
                    ->setRunAt(new DateTimeImmutable())
                    ->setRetryCount(0)
                    ->setBeds24BookIdOriginal($link->getBeds24BookId());

                if ($uow !== null) {
                    $uow->recomputeSingleEntityChangeSet(
                        class: $this->em->getClassMetadata(PmsBookingsPushQueue::class),
                        entity: $existingQueue
                    );
                }

                $this->runtimeDedupe[$dedupeKey] = $existingQueue;
                return;
            }
        }

        // 6. Creación de una nueva tarea en la cola.
        $queue = new PmsBookingsPushQueue();

        // ✅ FIX CRÍTICO: Determinar si debemos enlazar el objeto Link o solo sus datos
        $isDeleteAction = $isDelete
            || $endpoint->getAccion() === self::ENDPOINT_DELETE_BOOKINGS
            || $link->getStatus() === PmsEventoBeds24Link::STATUS_PENDING_DELETE;

        if ($isDeleteAction) {
            // 🛑 ROMPEMOS LA RELACIÓN para evitar que cascade:['persist'] resucite el link borrado
            $queue->setLink(null);

            // Aseguramos que los datos históricos estén presentes (Snapshot)
            $queue->setBeds24BookIdOriginal($link->getBeds24BookId());
            $queue->setLinkIdOriginal((string) $link->getId());
        } else {
            // Flujo normal: Enlazamos para integridad referencial
            $queue->setLink($link);
        }

        $queue
            ->setBeds24Config($map->getBeds24Config())
            ->setEndpoint($endpoint)
            ->setDedupeKey($dedupeKey)
            ->setPayloadHash($payloadHash)
            ->setStatus(PmsBookingsPushQueue::STATUS_PENDING)
            ->setRunAt(new DateTimeImmutable())
            // Siempre seteamos el original por si acaso
            ->setBeds24BookIdOriginal($link->getBeds24BookId());

        if (!$isDelete) {
            $link->addQueue($queue);
        }

        $this->em->persist($queue);

        if ($uow !== null) {
            $uow->computeChangeSet(
                class: $this->em->getClassMetadata(PmsBookingsPushQueue::class),
                entity: $queue
            );
        }

        $this->runtimeDedupe[$dedupeKey] = $queue;
    }

    private function findExistingQueue(PmsEventoBeds24Link $link, string $dedupeKey, ?UnitOfWork $uow): ?PmsBookingsPushQueue
    {
        if (isset($this->runtimeDedupe[$dedupeKey])) {
            return $this->runtimeDedupe[$dedupeKey];
        }

        $queues = $link->getQueues();
        if ($queues instanceof PersistentCollection && $queues->isInitialized()) {
            foreach ($queues as $q) {
                if ($q instanceof PmsBookingsPushQueue && $q->getDedupeKey() === $dedupeKey) {
                    return $q;
                }
            }
        }

        $isNewLink = ($uow !== null) && ($uow->isScheduledForInsert(entity: $link) || $uow->getEntityState(entity: $link) === UnitOfWork::STATE_NEW);
        if ($isNewLink) {
            return null;
        }

        $q = $this->em->getRepository(PmsBookingsPushQueue::class)->findOneBy([
            'dedupeKey' => $dedupeKey,
            'link'      => $link,
        ]);

        if ($q instanceof PmsBookingsPushQueue) {
            $this->runtimeDedupe[$dedupeKey] = $q;
        }

        return $q;
    }

    public function cancelPendingPostForLink(PmsEventoBeds24Link $link, ?string $reason = null, ?UnitOfWork $uow = null): bool
    {
        $changed = false;
        $queuesToCheck = [];
        $collection = $link->getQueues();

        if ($collection instanceof PersistentCollection && $collection->isInitialized()) {
            $queuesToCheck = $collection->toArray();
        } elseif ($link->getId() !== null) {
            $queuesToCheck = $this->em->getRepository(PmsBookingsPushQueue::class)->findBy([
                'link'   => $link,
                'status' => PmsBookingsPushQueue::STATUS_PENDING
            ]);
        }

        foreach ($queuesToCheck as $queue) {
            if (!$queue instanceof PmsBookingsPushQueue) continue;
            if ($uow !== null && $uow->isScheduledForDelete(entity: $queue)) continue;
            if ($queue->getEndpoint()?->getAccion() !== self::ENDPOINT_POST_BOOKINGS) continue;

            $queue->setStatus(PmsBookingsPushQueue::STATUS_CANCELLED)
                ->setFailedReason(sprintf('Cancelled: %s', $reason ?: 'manual cancel'))
                ->setRunAt(null);

            if ($uow !== null) {
                $uow->recomputeSingleEntityChangeSet(
                    class: $this->em->getClassMetadata(PmsBookingsPushQueue::class),
                    entity: $queue
                );
            }
            $changed = true;
        }

        return $changed;
    }
}