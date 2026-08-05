<?php

declare(strict_types=1);

namespace App\Pms\Service\Queue;

use App\Exchange\Entity\ExchangeEndpoint;
use App\Exchange\Enum\ConnectivityProvider;
use App\Exchange\Service\Context\SyncContext;
use App\Pms\Entity\PmsBookingsPushQueue;
use App\Pms\Entity\PmsEventoBeds24Link;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Factory\PmsBookingsPushQueueFactory;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Servicio Beds24BookingsPushQueueCreator.
 * Gestiona el encolado de sincronizaciones hacia Beds24.
 * Integrado con Factory para generación correcta de UUIDs v7.
 * Implementa ResetInterface para evitar fugas de memoria y entidades
 * "unmanaged" en workers asíncronos de larga duración.
 */
final class Beds24BookingsPushQueueCreator implements ResetInterface
{
    private const ENDPOINT_POST_BOOKINGS = PmsBookingsPushQueue::ACCION_POST_BOOKINGS;
    private const ENDPOINT_DELETE_BOOKINGS = PmsBookingsPushQueue::ACCION_DELETE_BOOKINGS;

    /**
     * Cache en memoria para evitar duplicados en el mismo ciclo de flush.
     * @var array<string, PmsBookingsPushQueue>
     */
    private array $runtimeDedupe = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SyncContext $syncContext,
        private readonly PmsBookingsPushQueueFactory $factory,
    ) {}

    /**
     * Limpia la memoria interna de la clase.
     * Symfony Messenger llama a este método automáticamente después de procesar
     * cada mensaje, garantizando que el caché no retenga entidades "desvinculadas"
     * (unmanaged) que provocarían errores en Doctrine tras un $em->clear().
     */
    public function reset(): void
    {
        $this->runtimeDedupe = [];
    }

    public function enqueueForLink(
        PmsEventoBeds24Link $link,
        ExchangeEndpoint    $endpoint,
        ?UnitOfWork         $uow = null
    ): void {
        // 0. Protección contra bucles
        if ($this->syncContext->isPush()) {
            return;
        }

        // Detectar si el link está marcado para borrado físico.
        $isDelete = ($uow !== null && $uow->isScheduledForDelete(entity: $link));

        // 1. Escudo PULL (Webhooks)
        // Evita el Efecto Eco para el enlace principal, pero permite que los
        // "espejos" generados durante el pull se sincronicen hacia Beds24.
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
        $providerVal = $endpoint->getProvider()?->value ?? ConnectivityProvider::BEDS24->value;
        $dedupeKey = sprintf('link:%s:provider:%s:endpoint:%s', $linkId, $providerVal, $endpoint->getAccion());

        // 4. Snapshot de datos (Payload JSON)
        $payload = [
            'linkId'       => $linkId,
            'isMirror'     => !$link->isEsPrincipal(),
            'eventoId'     => $evento?->getId() ? (string) $evento->getId() : null,
            'inicio'       => $evento?->getInicio()?->format('c'),
            'fin'          => $evento?->getFin()?->format('c'),
            'estado'       => $evento?->getEstado()?->getId(),
            'beds24RoomId' => $map->getBeds24RoomId(),
            'configId'     => $map->getPmsUnidad()->getEstablecimiento()->getBeds24Config()?->getId() ? (string) $map->getPmsUnidad()->getEstablecimiento()->getBeds24Config()->getId() : null,
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
                    ->setConfig($map->getPmsUnidad()->getEstablecimiento()->getBeds24Config())
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
        $queue = $this->factory->create(
            config: $map->getPmsUnidad()->getEstablecimiento()->getBeds24Config(),
            endpoint: $endpoint
        );

        // Validación de Acción + Proveedor para identificar si es un DELETE de Beds24
        $isDeleteAction = $isDelete
            || ($endpoint->getProvider() === ConnectivityProvider::BEDS24 && $endpoint->getAccion() === self::ENDPOINT_DELETE_BOOKINGS)
            || $link->getStatus() === PmsEventoBeds24Link::STATUS_PENDING_DELETE;

        if ($isDeleteAction) {
            // ROMPEMOS LA RELACIÓN para evitar resucitar el link borrado por cascada
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

        // ---------------------------------------------------------------------
        // GARANTÍA DE ORDEN PADRE → HIJO (no tocar sin leer esto)
        // ---------------------------------------------------------------------
        // `PmsBookingsPushQueue#link` tiene `cascade: ['persist']`, así que el persist de
        // abajo arrastra al link; y al alcanzarlo, Doctrine recorre `PmsEventoBeds24Link#evento`,
        // que NO cascadea. Si ese evento todavía no está gestionado —se está creando en este
        // mismo flush y la cascada que lo persiste aún no ha llegado a él— Doctrine aborta el
        // flush ENTERO con «A new entity was found through the relationship
        // PmsEventoBeds24Link#evento». Es esporádico porque depende del orden en que el
        // onFlush alcance cada entidad.
        //
        // Se arregla asegurando el padre antes que el hijo, y NO añadiendo otro
        // `cascade: persist` al mapeo del evento: encadenar cascadas sólo empuja el problema
        // un nivel más arriba (evento → reserva → unidad…) y hace que cualquier grafo a medio
        // construir se inserte por sorpresa. El cascade del link ya fue ese parche una vez.
        //
        // Sólo actúa en el caso que hoy revienta: si el evento ya está gestionado, no hace nada.
        if ($queue->getLink() !== null && $evento !== null && !$this->em->contains($evento)) {
            $this->em->persist($evento);

            if ($uow !== null) {
                $uow->computeChangeSet(
                    class: $this->em->getClassMetadata(PmsEventoCalendario::class),
                    entity: $evento
                );
            }
        }

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

            $ep = $queue->getEndpoint();

            // Solo cancelamos POSTs que pertenezcan explícitamente a BEDS24
            if ($ep === null || $ep->getProvider() !== ConnectivityProvider::BEDS24 || $ep->getAccion() !== self::ENDPOINT_POST_BOOKINGS) {
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

    /**
     * Desengancha del link TODAS sus colas históricas, dejando el snapshot en su sitio.
     *
     * Hay que llamarlo antes de que el link desaparezca por cascada. La FK
     * `pms_bookings_push_queue.link_id` es `ON DELETE SET NULL`, así que la base se
     * arreglaría sola; el problema es Doctrine en memoria:
     *
     *   1. Se borra un evento -> sus PmsEventoBeds24Link se van en cascada.
     *   2. Las colas viejas (el POST que creó la reserva, ya en `success`) siguen
     *      gestionadas y apuntando a ese link, y `PmsBookingsPushQueue#link` declara
     *      `cascade: ['persist']`.
     *   3. En el SIGUIENTE flush —el que hace PmsReservaRecalculoService dentro de su
     *      postFlush— Doctrine recorre esa asociación, alcanza el link ya borrado y desde
     *      él su `evento`, ambos en estado NEW, y aborta con «A new entity was found
     *      through the relationship PmsEventoBeds24Link#evento».
     *
     * Ese error es el que hacía imposible borrar una estancia, y el que obligó a
     * `PmsExtensionEstanciaService::retirar()` a cancelar en vez de borrar. Cortando la
     * referencia aquí, el grafo en memoria deja de apuntar a lo que ya no existe.
     */
    public function detachQueuesFromLink(PmsEventoBeds24Link $link, ?UnitOfWork $uow = null): void
    {
        $collection = $link->getQueues();

        $queues = ($collection instanceof PersistentCollection && !$collection->isInitialized() && $link->getId() !== null)
            ? $this->em->getRepository(PmsBookingsPushQueue::class)->findBy(['link' => $link])
            : $collection->toArray();

        foreach ($queues as $queue) {
            if (!$queue instanceof PmsBookingsPushQueue || $queue->getLink() === null) {
                continue;
            }

            // La cola es auditoría: no se borra, pero debe conservar a quién se refería.
            if ($queue->getBeds24BookIdOriginal() === null && $link->getBeds24BookId() !== null) {
                $queue->setBeds24BookIdOriginal($link->getBeds24BookId());
            }
            if ($queue->getLinkIdOriginal() === null) {
                $queue->setLinkIdOriginal((string) $link->getId());
            }

            $queue->setLink(null);

            // Dentro de onFlush el changeset ya está calculado: hay que recomputarlo o el
            // UPDATE de `link_id` no se emite.
            if ($uow !== null && !$uow->isScheduledForDelete(entity: $queue)) {
                $uow->recomputeSingleEntityChangeSet(
                    class: $this->em->getClassMetadata(PmsBookingsPushQueue::class),
                    entity: $queue
                );
            }
        }

        // Vaciar el lado inverso para que nada en memoria siga alcanzando al link.
        $collection->clear();
    }
}