<?php
declare(strict_types=1);

namespace App\Pms\EventListener;

use App\Exchange\Service\Context\SyncContext;
use App\Pms\Entity\PmsBeds24Endpoint;
use App\Pms\Entity\PmsEventoBeds24Link;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsReserva;
use App\Pms\Service\Beds24\Queue\Beds24BookingsPushQueueCreator;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork;
use SplObjectStorage;

#[AsDoctrineListener(event: Events::onFlush, priority: 200)]
final class Beds24BookingsPushQueueListener
{
    private const ENDPOINT_POST_BOOKINGS   = 'POST_BOOKINGS';
    private const ENDPOINT_DELETE_BOOKINGS = 'DELETE_BOOKINGS';

    private const IGNORED_FIELDS_ON_LOCKED_OTA = [
        'nombreCliente', 'apellidoCliente', 'emailCliente', 'telefono', 'telefono2',
        'nota', 'comentariosHuesped', 'horaLlegadaCanal', 'pais', 'idioma',
        'documento', 'tipoDocumento', 'direccion', 'ciudad', 'codPostal'
    ];

    private SplObjectStorage $eventosTouched;
    private SplObjectStorage $reservasTouched;
    private SplObjectStorage $linksTouched;
    private SplObjectStorage $linksDeleted;

    private ?PmsBeds24Endpoint $cachedPostEndpoint = null;
    private ?PmsBeds24Endpoint $cachedDeleteEndpoint = null;
    private bool $endpointsLoaded = false;

    public function __construct(
        private readonly Beds24BookingsPushQueueCreator $queueCreator,
        private readonly SyncContext $syncContext,
    ) {
        $this->reset();
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if ($this->syncContext->isPush()) {
            return;
        }

        $em  = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        // 1. Recolectar cambios
        foreach ($uow->getScheduledEntityInsertions() as $e) $this->collect($e);

        foreach ($uow->getScheduledEntityUpdates() as $e) {
            if ($e instanceof PmsReserva && $this->shouldIgnoreReservaUpdate($e, $uow)) {
                continue;
            }
            $this->collect($e);
        }

        foreach ($uow->getScheduledEntityDeletions() as $e) {
            $this->collectDeleted($e);
            $this->collect($e);
        }

        foreach ($uow->getScheduledCollectionUpdates() as $c) {
            if (is_object($c->getOwner())) $this->collect($c->getOwner());
        }
        foreach ($uow->getScheduledCollectionDeletions() as $c) {
            if (is_object($c->getOwner())) $this->collect($c->getOwner());
        }

        if ($this->isClean()) {
            return;
        }

        $links = $this->resolveLinks();
        if ($links === []) {
            $this->reset();
            return;
        }

        $this->loadEndpoints($em);
        if ($this->cachedPostEndpoint === null && $this->cachedDeleteEndpoint === null) {
            $this->reset();
            return;
        }

        // 2. Procesar Links Recolectados
        foreach ($links as $link) {
            if ($link->getUnidadBeds24Map() === null) continue;

            // [VALIDACIÓN EXTRA] Ignorar links asociados a eventos 'fantasmas' (detached/new sin insert)
            // Esto complementa el escudo del Creator, evitando procesar basura en memoria.
            if (!$this->isLinkBeingDeleted($link)) {
                $evento = $link->getEvento();
                if ($evento) {
                    $state = $uow->getEntityState($evento);
                    // Si es NEW y no está programado para insertarse (p.ej. se borró), SKIP.
                    if (($state === UnitOfWork::STATE_NEW && !$uow->isScheduledForInsert($evento)) ||
                        $state === UnitOfWork::STATE_DETACHED) {
                        continue;
                    }
                }
            }

            $endpoint = $this->resolveEndpointForLink($link);

            // Cancelación local
            if ($endpoint === null) {
                if ($this->shouldCancelPostWithoutRemoteDelete($link)) {
                    $this->queueCreator->cancelPendingPostForLink(
                        $link,
                        'Link eliminado o pending_delete sin beds24BookId',
                        $uow
                    );
                }
                continue;
            }

            $this->queueCreator->enqueueForLink($link, $endpoint, $uow);
        }

        $this->reset();
    }

    // ... (Métodos privados auxiliares) ...

    private function isLinkBeingDeleted(PmsEventoBeds24Link $link): bool
    {
        return ($this->linksDeleted->contains($link) || $link->getStatus() === PmsEventoBeds24Link::STATUS_PENDING_DELETE);
    }

    private function resolveEndpointForLink(PmsEventoBeds24Link $link): ?PmsBeds24Endpoint
    {
        $isDelete = $this->isLinkBeingDeleted($link);

        if ($isDelete) {
            $bookId = $link->getBeds24BookId();

            // [RESCATE] Buscamos en el historial si perdimos el ID
            if (!$bookId) {
                $bookId = $this->getHistoricalBookId($link);
                // Inyección temporal para que el Creator lo vea
                if ($bookId) {
                    $link->setBeds24BookId($bookId);
                }
            }

            return $bookId ? $this->cachedDeleteEndpoint : null;
        }

        if ($link->getStatus() === PmsEventoBeds24Link::STATUS_ACTIVE || $link->getStatus() === PmsEventoBeds24Link::STATUS_PENDING_MOVE) {
            return $this->cachedPostEndpoint;
        }
        return null;
    }

    private function getHistoricalBookId(PmsEventoBeds24Link $link): ?string
    {
        $queues = $link->getQueues();
        if ($queues instanceof PersistentCollection && !$queues->isInitialized()) {
            return null;
        }
        foreach ($queues as $q) {
            if ($q->getBeds24BookIdOriginal()) {
                return $q->getBeds24BookIdOriginal();
            }
        }
        return null;
    }

    private function shouldCancelPostWithoutRemoteDelete(PmsEventoBeds24Link $link): bool
    {
        return $this->isLinkBeingDeleted($link);
    }

    private function shouldIgnoreReservaUpdate(PmsReserva $reserva, UnitOfWork $uow): bool
    {
        if (!$reserva->isDatosLocked()) return false;
        $channel = $reserva->getChannel();
        $isOta = $channel !== null && !empty($channel->getBeds24ChannelId());
        if (!$isOta) return false;
        $changeSet = $uow->getEntityChangeSet($reserva);
        $changedFields = array_keys($changeSet);
        $relevantChanges = array_diff($changedFields, self::IGNORED_FIELDS_ON_LOCKED_OTA);
        return count($relevantChanges) === 0;
    }

    private function loadEndpoints(EntityManagerInterface $em): void
    {
        if ($this->endpointsLoaded) return;
        $repo = $em->getRepository(PmsBeds24Endpoint::class);
        $this->cachedPostEndpoint = $repo->findOneBy(['accion' => self::ENDPOINT_POST_BOOKINGS, 'activo' => true]);
        $this->cachedDeleteEndpoint = $repo->findOneBy(['accion' => self::ENDPOINT_DELETE_BOOKINGS, 'activo' => true]);
        $this->endpointsLoaded = true;
    }

    private function collect(object $e): void
    {
        if ($e instanceof PmsEventoCalendario) $this->eventosTouched->attach($e);
        elseif ($e instanceof PmsReserva) $this->reservasTouched->attach($e);
        elseif ($e instanceof PmsEventoBeds24Link) $this->linksTouched->attach($e);
    }

    private function collectDeleted(object $e): void
    {
        if ($e instanceof PmsEventoBeds24Link) $this->linksDeleted->attach($e);
    }

    private function resolveLinks(): array
    {
        $resolved = new SplObjectStorage();
        foreach ($this->linksTouched as $l) $resolved->attach($l);
        foreach ($this->linksDeleted as $l) $resolved->attach($l);
        foreach ($this->eventosTouched as $e) foreach ($e->getBeds24Links() as $l) $resolved->attach($l);
        foreach ($this->reservasTouched as $r) foreach ($r->getEventosCalendario() as $e) foreach ($e->getBeds24Links() as $l) $resolved->attach($l);

        $out = [];
        foreach ($resolved as $link) {
            $evento = $link->getEvento();
            if (!$evento || !$evento->getInicio() || !$evento->getFin()) continue;
            if ($link->getStatus() === PmsEventoBeds24Link::STATUS_SYNCED_DELETED) continue;
            $key = $link->getId() ?? spl_object_id($link);
            $out[$key] = $link;
        }
        return array_values($out);
    }

    private function isClean(): bool
    {
        return $this->eventosTouched->count() === 0 &&
            $this->reservasTouched->count() === 0 &&
            $this->linksTouched->count() === 0 &&
            $this->linksDeleted->count() === 0;
    }

    private function reset(): void
    {
        $this->eventosTouched  = new SplObjectStorage();
        $this->reservasTouched = new SplObjectStorage();
        $this->linksTouched    = new SplObjectStorage();
        $this->linksDeleted    = new SplObjectStorage();
    }
}