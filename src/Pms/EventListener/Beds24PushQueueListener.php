<?php
declare(strict_types=1);

namespace App\Pms\EventListener;

use App\Pms\Entity\PmsBeds24Endpoint;
use App\Pms\Entity\PmsEventoBeds24Link;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsReserva;
use App\Pms\Entity\PmsBeds24LinkQueue;
use App\Pms\Service\Beds24\Queue\Beds24LinkQueueCreator;
use App\Pms\Service\Beds24\Sync\SyncContext;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;
use SplObjectStorage;

#[AsDoctrineListener(event: Events::onFlush, priority: -900)]
final class Beds24PushQueueListener
{
    /**
     * Acciones Beds24 soportadas por este listener.
     * No se hardcodea lógica de API aquí, solo intención.
     */
    private const ENDPOINT_POST_BOOKINGS   = 'POST_BOOKINGS';
    private const ENDPOINT_DELETE_BOOKINGS = 'DELETE_BOOKINGS';

    /**
     * Storages temporales para detectar cambios durante el flush.
     * Usamos SplObjectStorage para evitar duplicados por identidad de objeto.
     */
    private SplObjectStorage $eventosTouched;
    private SplObjectStorage $reservasTouched;
    private SplObjectStorage $linksTouched;
    private SplObjectStorage $linksDeleted;

    public function __construct(
        private readonly Beds24LinkQueueCreator $queueCreator,
        private readonly SyncContext $syncContext,
    ) {
        $this->eventosTouched  = new SplObjectStorage();
        $this->reservasTouched = new SplObjectStorage();
        $this->linksTouched    = new SplObjectStorage();
        $this->linksDeleted    = new SplObjectStorage();
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        /**
         * 🚫 CORTE TOTAL DE LOOP
         *
         * Si el flush ocurre dentro de un PUSH:
         * - No se deben crear nuevas colas
         * - No se deben reactivar colas
         * - No se debe tocar dedupe
         *
         * Esto evita el bug clásico:
         *   "worker ejecuta → flush → listener re-encola → worker infinito"
         */
        if ($this->syncContext->isPush()) {
            return;
        }

        $em  = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        /**
         * Recolectamos TODAS las entidades tocadas:
         * - inserts
         * - updates
         * - deletes
         * - cambios por colecciones (add/remove)
         *
         * Esto es clave porque los links cuelgan del evento y de la reserva.
         */
        foreach ($uow->getScheduledEntityInsertions() as $e) {
            $this->collect($e);
        }
        foreach ($uow->getScheduledEntityUpdates() as $e) {
            $this->collect($e);
        }
        foreach ($uow->getScheduledEntityDeletions() as $e) {
            $this->collectDeleted($e);
            $this->collect($e);
        }

        foreach ($uow->getScheduledCollectionUpdates() as $c) {
            $owner = $c->getOwner();
            if (is_object($owner)) {
                $this->collect($owner);
            }
        }

        foreach ($uow->getScheduledCollectionDeletions() as $c) {
            $owner = $c->getOwner();
            if (is_object($owner)) {
                $this->collect($owner);
            }
        }

        // Fast exit: nada relevante tocado
        if (
            $this->eventosTouched->count() === 0 &&
            $this->reservasTouched->count() === 0 &&
            $this->linksTouched->count() === 0 &&
            $this->linksDeleted->count() === 0
        ) {
            return;
        }

        /**
         * Resolver links finales a procesar.
         * Aquí se hace el "flatten" de evento → links → reserva → links.
         */
        $links = $this->resolveLinks();
        if ($links === []) {
            $this->reset();
            return;
        }

        // Resolver endpoints activos una sola vez
        $repo = $em->getRepository(PmsBeds24Endpoint::class);
        $postEndpoint   = $repo->findOneBy(['accion' => self::ENDPOINT_POST_BOOKINGS, 'activo' => true]);
        $deleteEndpoint = $repo->findOneBy(['accion' => self::ENDPOINT_DELETE_BOOKINGS, 'activo' => true]);

        if ($postEndpoint === null && $deleteEndpoint === null) {
            $this->reset();
            return;
        }

        /**
         * IMPORTANTE:
         * El creator NO hace flush.
         * Si modifica colas, debemos computar changesets manualmente
         * dentro de ESTE MISMO flush.
         */
        $queueMeta = $em->getClassMetadata(PmsBeds24LinkQueue::class);

        foreach ($links as $link) {
            // Sin map no hay destino Beds24
            if ($link->getUnidadBeds24Map() === null) {
                continue;
            }

            $endpoint = $this->resolveEndpointForLink($link, $postEndpoint, $deleteEndpoint);

            /**
             * Caso especial:
             * - link eliminado o pending_delete
             * - SIN beds24BookId
             * ⇒ cancelar POST pendiente, NO enviar DELETE
             */
            if ($endpoint === null) {
                if ($this->shouldCancelPostWithoutRemoteDelete($link)) {
                    $this->queueCreator->cancelPendingPostForLink(
                        $link,
                        'Link eliminado o pending_delete sin beds24BookId'
                    );
                    $this->computeQueueChangeSetsForLink($link, $em, $uow, $queueMeta);
                }
                continue;
            }

            // Encolado normal
            $this->queueCreator->enqueueForLink($link, $endpoint);
            $this->computeQueueChangeSetsForLink($link, $em, $uow, $queueMeta);
        }

        $this->reset();
    }

    // ====================== helpers ======================

    private function collect(object $e): void
    {
        if ($e instanceof PmsEventoCalendario) {
            $this->eventosTouched->attach($e); return;
        }
        if ($e instanceof PmsReserva) {
            $this->reservasTouched->attach($e); return;
        }
        if ($e instanceof PmsEventoBeds24Link) {
            $this->linksTouched->attach($e);
        }
    }

    private function collectDeleted(object $e): void
    {
        if ($e instanceof PmsEventoBeds24Link) {
            $this->linksDeleted->attach($e);
        }
    }

    /**
     * Resuelve el conjunto FINAL de links a evaluar.
     * Elimina duplicados y filtra estados no operables.
     */
    private function resolveLinks(): array
    {
        $resolved = new SplObjectStorage();

        foreach ($this->linksTouched as $l)   { $resolved->attach($l); }
        foreach ($this->linksDeleted as $l)   { $resolved->attach($l); }

        foreach ($this->eventosTouched as $e) {
            foreach ($e->getBeds24Links() as $l) {
                $resolved->attach($l);
            }
        }

        foreach ($this->reservasTouched as $r) {
            foreach ($r->getEventosCalendario() as $e) {
                foreach ($e->getBeds24Links() as $l) {
                    $resolved->attach($l);
                }
            }
        }

        $out = [];
        foreach ($resolved as $link) {
            $evento = $link->getEvento();

            // Guards mínimos
            if (!$evento || !$evento->getInicio() || !$evento->getFin()) {
                continue;
            }

            if ($link->getStatus() === PmsEventoBeds24Link::STATUS_SYNCED_DELETED) {
                continue;
            }

            $out[$link->getId() ?? spl_object_id($link)] = $link;
        }

        return array_values($out);
    }

    private function resolveEndpointForLink(
        PmsEventoBeds24Link $link,
        ?PmsBeds24Endpoint $post,
        ?PmsBeds24Endpoint $delete
    ): ?PmsBeds24Endpoint {
        if ($this->linksDeleted->contains($link) || $link->getStatus() === PmsEventoBeds24Link::STATUS_PENDING_DELETE) {
            return $link->getBeds24BookId() ? $delete : null;
        }

        if (
            $link->getStatus() === PmsEventoBeds24Link::STATUS_ACTIVE ||
            $link->getStatus() === PmsEventoBeds24Link::STATUS_PENDING_MOVE
        ) {
            return $post;
        }

        return null;
    }

    private function shouldCancelPostWithoutRemoteDelete(PmsEventoBeds24Link $link): bool
    {
        return (
            ($this->linksDeleted->contains($link) ||
                $link->getStatus() === PmsEventoBeds24Link::STATUS_PENDING_DELETE)
            && !$link->getBeds24BookId()
        );
    }

    /**
     * Recalcula changesets de colas creadas/modificadas en memoria.
     * Evita errores HY093 y estados "fantasma".
     */
    private function computeQueueChangeSetsForLink(
        PmsEventoBeds24Link $link,
                            $em,
        UnitOfWork $uow,
                            $meta
    ): void {
        foreach ($link->getQueues() as $queue) {
            if ($uow->isScheduledForInsert($queue) || $uow->getEntityState($queue) === UnitOfWork::STATE_NEW) {
                $em->persist($queue);
                $uow->computeChangeSet($meta, $queue);
                continue;
            }

            if ($uow->getEntityState($queue) === UnitOfWork::STATE_MANAGED) {
                $uow->recomputeSingleEntityChangeSet($meta, $queue);
            }
        }
    }

    private function reset(): void
    {
        $this->eventosTouched  = new SplObjectStorage();
        $this->reservasTouched = new SplObjectStorage();
        $this->linksTouched    = new SplObjectStorage();
        $this->linksDeleted    = new SplObjectStorage();
    }
}