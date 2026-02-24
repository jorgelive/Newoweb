<?php

declare(strict_types=1);

namespace App\Pms\Service\Beds24\Queue;

use App\Exchange\Service\Context\SyncContext;
use App\Pms\Entity\Beds24Endpoint;
use App\Pms\Entity\PmsBookingsPushQueue;
use App\Pms\Entity\PmsEventoBeds24Link;
use App\Pms\Factory\PmsBookingsPushQueueFactory;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork;

/**
 * Servicio Beds24BookingsPushQueueCreator.
 * Gestiona el encolado de sincronizaciones hacia Beds24.
 * Integrado con Factory para generación correcta de UUIDs v7.
 */
final class Beds24BookingsPushQueueCreator
{
    private const ENDPOINT_POST_BOOKINGS = 'POST_BOOKINGS';
    private const ENDPOINT_DELETE_BOOKINGS = 'DELETE_BOOKINGS';

    /**
     * Cache en memoria para evitar duplicados en el mismo ciclo de flush.
     * @var array<string, PmsBookingsPushQueue>
     */
    private array $runtimeDedupe = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SyncContext $syncContext,
        private readonly PmsBookingsPushQueueFactory $factory, // ✅ Inyección del Factory
    ) {}

    public function enqueueForLink(
        PmsEventoBeds24Link $link,
        Beds24Endpoint      $endpoint,
        ?UnitOfWork         $uow = null
    ): void {
        // 0. Protección contra bucles
        if ($this->syncContext->isPush()) {
            return;
        }

        // Detectar si el link está marcado para borrado físico.
        $isDelete = ($uow !== null && $uow->isScheduledForDelete(entity: $link));

        // 1. Escudo PULL (Webhooks)
        if ($this->syncContext->isPull()) {
            if ($link->isEsPrincipal()) {
                return;
            }
        }

        // 2. Validaciones básicas
        $evento = $link->getEvento();
        if (!$evento && !$isDelete) {
            return;
        }

        $map = $link->getUnidadBeds24Map();
        if ($map === null) {
            return;
        }

        // 3. Deduplicación
        $linkId = (string) $link->getId();
        $dedupeKey = sprintf('link:%s:endpoint:%s', $linkId, $endpoint->getAccion());

        // 4. Snapshot de datos (Payload JSON)
        $payload = [
            'linkId'       => $linkId,
            'isMirror'     => !$link->isEsPrincipal(),
            'eventoId'     => $evento?->getId() ? (string) $evento->getId() : null,
            'inicio'       => $evento?->getInicio()?->format('c'),
            'fin'          => $evento?->getFin()?->format('c'),
            'estado'       => $evento?->getEstado()?->getId(),
            'beds24RoomId' => $map->getBeds24RoomId(),
            'configId'     => $map->getPmsUnidad()->getEstablecimiento()->getConfig()?->getId() ? (string) $map->getPmsUnidad()->getEstablecimiento()->getConfig()->getId() : null,
        ];

        $json = json_encode(value: $payload, flags: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $payloadHash = sha1((string) $json);

        // 5. Idempotencia (Reusar existente si es posible)
        if (!$isDelete) {
            $existingQueue = $this->findExistingQueue(link: $link, dedupeKey: $dedupeKey, uow: $uow);

            if ($existingQueue instanceof PmsBookingsPushQueue) {
                // Si es idéntico y está pendiente, no hacemos nada (Ahorro)
                if ($existingQueue->getPayloadHash() === $payloadHash
                    && $existingQueue->getStatus() === PmsBookingsPushQueue::STATUS_PENDING
                ) {
                    return;
                }

                // Si cambió, reciclamos la tarea existente
                $existingQueue
                    ->setConfig($map->getPmsUnidad()->getEstablecimiento()->getConfig())
                    ->setEndpoint($endpoint)
                    ->setPayloadHash($payloadHash)
                    ->setStatus(PmsBookingsPushQueue::STATUS_PENDING)
                    ->setRunAt(new DateTimeImmutable())
                    ->setRetryCount(0)
                    ->setBeds24BookIdOriginal($link->getBeds24BookId())
                    ->setLockedAt(null)
                    ->setLockedBy(null)
                    ->setFailedReason(null);

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

        // 6. CREACIÓN NUEVA (USANDO FACTORY)
        // ✅ Usamos el Factory para obtener una instancia con UUID v7 y defaults seguros
        $queue = $this->factory->create(
            config: $map->getPmsUnidad()->getEstablecimiento()->getConfig(),
            endpoint: $endpoint
        );

        // Lógica de integridad referencial para borrados
        $isDeleteAction = $isDelete
            || $endpoint->getAccion() === self::ENDPOINT_DELETE_BOOKINGS
            || $link->getStatus() === PmsEventoBeds24Link::STATUS_PENDING_DELETE;

        if ($isDeleteAction) {
            // 🛑 ROMPEMOS LA RELACIÓN para evitar resucitar el link borrado por cascada
            $queue->setLink(null);

            // Snapshot histórico obligatorio
            $queue->setBeds24BookIdOriginal($link->getBeds24BookId());
            $queue->setLinkIdOriginal((string) $link->getId());
        } else {
            // Enlace normal
            $queue->setLink($link);
            // Agregamos al lado inverso para que Doctrine lo detecte en memoria
            $link->addQueue($queue);
        }

        // Seteamos los datos específicos
        $queue
            ->setDedupeKey($dedupeKey)
            ->setPayloadHash($payloadHash)
            ->setBeds24BookIdOriginal($link->getBeds24BookId());

        // Persistir
        $this->em->persist($queue);

        // Notificar al UnitOfWork que hay una nueva entidad (Vital dentro de onFlush)
        if ($uow !== null) {
            $uow->computeChangeSet(
                class: $this->em->getClassMetadata(PmsBookingsPushQueue::class),
                entity: $queue
            );
        }

        $this->runtimeDedupe[$dedupeKey] = $queue;
    }

    /**
     * Busca si ya existe una tarea en memoria o en base de datos para no duplicar.
     */
    private function findExistingQueue(PmsEventoBeds24Link $link, string $dedupeKey, ?UnitOfWork $uow): ?PmsBookingsPushQueue
    {
        // 1. Check Cache Runtime (mismo request)
        if (isset($this->runtimeDedupe[$dedupeKey])) {
            return $this->runtimeDedupe[$dedupeKey];
        }

        // 2. Check Colección en Memoria (Doctrine Identity Map)
        $queues = $link->getQueues();
        if ($queues instanceof PersistentCollection && $queues->isInitialized()) {
            foreach ($queues as $q) {
                if ($q instanceof PmsBookingsPushQueue && $q->getDedupeKey() === $dedupeKey) {
                    return $q;
                }
            }
        }

        // Si el link es nuevo, no puede tener nada en BD todavía
        $isNewLink = ($uow !== null) && ($uow->isScheduledForInsert(entity: $link) || $uow->getEntityState(entity: $link) === UnitOfWork::STATE_NEW);
        if ($isNewLink) {
            return null;
        }

        // 3. Check Base de Datos (Último recurso)
        $q = $this->em->getRepository(PmsBookingsPushQueue::class)->findOneBy([
            'dedupeKey' => $dedupeKey,
            'link'      => $link,
        ]);

        if ($q instanceof PmsBookingsPushQueue) {
            $this->runtimeDedupe[$dedupeKey] = $q;
        }

        return $q;
    }

    /**
     * Cancela cualquier tarea pendiente de tipo POST para un link dado.
     * Útil para evitar enviar datos de un link que acaba de convertirse en zombie o borrarse.
     */
    public function cancelPendingPostForLink(PmsEventoBeds24Link $link, ?string $reason = null, ?UnitOfWork $uow = null): bool
    {
        $changed = false;
        $queuesToCheck = [];
        $collection = $link->getQueues();

        // Obtener candidatos (Memoria vs BD)
        if ($collection instanceof PersistentCollection && $collection->isInitialized()) {
            $queuesToCheck = $collection->toArray();
        } elseif ($link->getId() !== null) {
            $queuesToCheck = $this->em->getRepository(PmsBookingsPushQueue::class)->findBy([
                'link'   => $link,
                'status' => PmsBookingsPushQueue::STATUS_PENDING
            ]);
        }

        foreach ($queuesToCheck as $queue) {
            if (!$queue instanceof PmsBookingsPushQueue) {
                continue;
            }

            // Si la cola también se está borrando, ignoramos
            if ($uow !== null && $uow->isScheduledForDelete(entity: $queue)) {
                continue;
            }

            // Solo cancelamos POSTs, los DELETEs deben seguir su curso
            if ($queue->getEndpoint()?->getAccion() !== self::ENDPOINT_POST_BOOKINGS) {
                continue;
            }

            // Aplicar cancelación
            $queue->setStatus(PmsBookingsPushQueue::STATUS_CANCELLED)
                ->setFailedReason(sprintf('Cancelled: %s', $reason ?: 'manual cancel'))
                ->setRunAt(null);

            // Avisar a Doctrine del cambio
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