<?php

declare(strict_types=1);

namespace App\Pms\EventListener\Queue;

use App\Exchange\Dispatch\RunExchangeTaskDispatch;
use App\Exchange\Service\Context\SyncContext;
use App\Pms\Entity\Beds24Endpoint;
use App\Pms\Entity\PmsBookingsPushQueue;
use App\Pms\Entity\PmsEventoBeds24Link;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsReserva;
use App\Pms\Service\Beds24\Queue\Beds24BookingsPushQueueCreator;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;
use SplObjectStorage;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Listener Beds24BookingsPushQueueListener.
 * Monitorea el UnitOfWork de Doctrine para detectar cambios en Reservas/Eventos/Links
 * y encolar la sincronización hacia Beds24.
 */
#[AsDoctrineListener(event: Events::onFlush, priority: 200)]
#[AsDoctrineListener(event: Events::postFlush, priority: 200)]
final class Beds24BookingsPushQueueListener
{
    private const ENDPOINT_POST_BOOKINGS   = 'POST_BOOKINGS';
    private const ENDPOINT_DELETE_BOOKINGS = 'DELETE_BOOKINGS';

    // Campos que no disparan sincronización si la reserva está bloqueada (OTA)
    private const IGNORED_FIELDS_ON_LOCKED_OTA = [
        'nombreCliente', 'apellidoCliente', 'emailCliente', 'telefono', 'telefono2',
        'nota', 'comentariosHuesped', 'horaLlegadaCanal', 'pais', 'idioma',
    ];

    private SplObjectStorage $eventosTouched;
    private SplObjectStorage $reservasTouched;
    private SplObjectStorage $linksTouched;
    private SplObjectStorage $linksDeleted;

    /** @var string[] IDs recolectados para despacho inmediato */
    private array $queuedIdsForDispatch = [];

    private ?Beds24Endpoint $cachedPostEndpoint = null;
    private ?Beds24Endpoint $cachedDeleteEndpoint = null;
    private bool $endpointsLoaded = false;

    public function __construct(
        private readonly Beds24BookingsPushQueueCreator $queueCreator,
        private readonly SyncContext $syncContext,
        private readonly MessageBusInterface $bus
    ) {
        $this->reset();
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        // Si ya estamos en modo PUSH (enviando a Beds24), ignoramos para evitar bucles.
        if ($this->syncContext->isPush()) {
            return;
        }

        $em = $args->getObjectManager();
        if (!$em instanceof EntityManagerInterface) {
            return;
        }

        $uow = $em->getUnitOfWork();

        // 1) RASTREO DE ENTIDADES DIRECTAS (Insertadas)
        foreach ($uow->getScheduledEntityInsertions() as $e) {
            $this->collect($e);
        }

        // 2) RASTREO DE ENTIDADES ACTUALIZADAS
        foreach ($uow->getScheduledEntityUpdates() as $e) {
            // Si es una reserva OTA y los cambios son solo cosméticos, ignoramos.
            if ($e instanceof PmsReserva && $this->shouldIgnoreReservaUpdate($e, $uow)) {
                continue;
            }
            $this->collect($e);
        }

        // 3) RASTREO DE ENTIDADES BORRADAS
        foreach ($uow->getScheduledEntityDeletions() as $e) {
            $this->collectDeleted($e);
            $this->collect($e);
        }

        // 4) RASTREO DE COLECCIONES (crucial para el Factory)
        foreach ($uow->getScheduledCollectionUpdates() as $c) {
            if (is_object($c->getOwner())) {
                $this->collect($c->getOwner());
            }
        }
        foreach ($uow->getScheduledCollectionDeletions() as $c) {
            if (is_object($c->getOwner())) {
                $this->collect($c->getOwner());
            }
        }

        if ($this->isClean()) {
            $this->reset();
            return;
        }

        // 5) RESOLUCIÓN DE TAREAS (PUSH, DELETE o CANCEL)
        $tasks = $this->resolveTasks($uow);
        if ($tasks === []) {
            $this->reset();
            return;
        }

        $this->loadEndpoints($em);

        foreach ($tasks as $task) {
            /** @var PmsEventoBeds24Link $link */
            $link = $task['link'];
            $action = $task['action'];

            // A) Cancelación de redundantes / huérfanos (zombies)
            if ($action === 'CANCEL') {
                $this->queueCreator->cancelPendingPostForLink($link, 'Redundant/Orphan link (Smart Move)', $uow);
                continue;
            }

            // B) Sincronización (PUSH o DELETE)
            $endpoint = ($action === 'DELETE') ? $this->cachedDeleteEndpoint : $this->cachedPostEndpoint;
            if ($endpoint === null) {
                continue;
            }

            if ($action === 'DELETE') {
                $this->ensureBookIdForDelete($link, $em, $uow);

                // Cancelar siempre cualquier POST pendiente asociado a este link.
                $this->queueCreator->cancelPendingPostForLink($link, 'Link deleted (replaced by DELETE)', $uow);

                // Si tras intentar recuperar no hay ID, no podemos borrar nada en Beds24.
                if (!$link->getBeds24BookId()) {
                    continue;
                }
            }

            $this->queueCreator->enqueueForLink($link, $endpoint, $uow);
        }

        // 6) CAPTURA DE IDs PARA DISPATCH (Pre-Commit)
        // Antes de cerrar la transacción, miramos qué colas nuevas se van a guardar.
        // 6) CAPTURA DE IDs PARA DISPATCH (Solo lo ejecutable)
        // Recolectamos colas que entran o vuelven a estado PENDIENTE.

        // A) Nuevas colas (INSERT)
        // Siempre nacen como PENDING gracias al Factory.
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof PmsBookingsPushQueue) { // O PmsRatesPushQueue
                $this->queuedIdsForDispatch[] = (string) $entity->getId();
            }
        }

        // B) Colas recicladas o reactivadas (UPDATE)
        // Solo nos interesa si el estado actual es PENDING.
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof PmsBookingsPushQueue) {
                if ($entity->getStatus() === PmsBookingsPushQueue::STATUS_PENDING) {
                    $this->queuedIdsForDispatch[] = (string) $entity->getId();
                }
            }
        }

        // NOTA: Ignoramos Deletions porque un ID borrado no existe para el worker.
        $this->resetPartial();
    }

    /**
     * ✅ Se ejecuta DESPUÉS del commit en base de datos.
     */
    public function postFlush(PostFlushEventArgs $args): void
    {
        if (empty($this->queuedIdsForDispatch)) {
            return;
        }

        // Copiamos y limpiamos duplicados
        $idsBatch = array_unique($this->queuedIdsForDispatch);

        // Limpiamos la propiedad de clase inmediatamente
        $this->queuedIdsForDispatch = [];

        // 🔥 Disparo Asíncrono de la orden al Worker
        $this->bus->dispatch(new RunExchangeTaskDispatch(
            taskName: 'bookings_push',
            ids: $idsBatch
        ));
    }

    /**
     * Analiza todos los links afectados y determina qué hacer con cada uno.
     * @return array<int, array{link: PmsEventoBeds24Link, action: string}>
     */
    private function resolveTasks(UnitOfWork $uow): array
    {
        $resolved = new SplObjectStorage();

        // Recolectar links directamente involucrados
        foreach ($this->linksTouched as $l) {
            $resolved->attach($l);
        }
        foreach ($this->linksDeleted as $l) {
            $resolved->attach($l);
        }

        // Links alcanzados por eventos tocados
        foreach ($this->eventosTouched as $e) {
            /** @var PmsEventoCalendario $e */
            foreach ($e->getBeds24Links() as $l) {
                $resolved->attach($l);
            }

            // Detectar links huérfanos en memoria (programados para delete)
            foreach ($uow->getScheduledEntityDeletions() as $ent) {
                if ($ent instanceof PmsEventoBeds24Link && $ent->getEvento() === $e) {
                    $resolved->attach($ent);
                }
            }
        }

        // Links alcanzados por reservas tocadas
        foreach ($this->reservasTouched as $r) {
            /** @var PmsReserva $r */
            foreach ($r->getEventosCalendario() as $e) {
                foreach ($e->getBeds24Links() as $l) {
                    $resolved->attach($l);
                }
            }
        }

        $tasks = [];
        $activeLinksByMap = [];

        // FASE 1: Clasificación inicial
        foreach ($resolved as $link) {
            // ✅ FIX: Validación explícita de tipo para calmar al IDE
            if (!$link instanceof PmsEventoBeds24Link) {
                continue;
            }

            // 1) Prioridad absoluta: borrados
            if ($this->isLinkBeingDeleted($link)) {
                $tasks[] = ['link' => $link, 'action' => 'DELETE'];
                continue;
            }

            // 2) Activos: agrupar para deduplicación
            $evento = $link->getEvento();
            $map = $link->getUnidadBeds24Map();

            if (!$evento || !$map) {
                // Activo pero huérfano -> cancelar pendientes (zombie)
                $tasks[] = ['link' => $link, 'action' => 'CANCEL'];
                continue;
            }

            // Clave: Evento + Mapa
            $key = spl_object_id($evento) . '|' . spl_object_id($map);
            $activeLinksByMap[$key][] = $link;
        }

        // FASE 2: Torneo de links por (evento,map)
        foreach ($activeLinksByMap as $group) {
            $winner = $group[0];
            foreach ($group as $l) {
                $winner = $this->pickBestLink($winner, $l);
            }

            foreach ($group as $l) {
                if ($l === $winner) {
                    $tasks[] = ['link' => $l, 'action' => 'PUSH'];
                } else {
                    $tasks[] = ['link' => $l, 'action' => 'CANCEL'];
                }
            }
        }

        return $tasks;
    }

    private function reset(): void
    {
        $this->queuedIdsForDispatch = [];
        $this->resetPartial();
    }

    private function resetPartial(): void
    {
        $this->eventosTouched = new SplObjectStorage();
        $this->reservasTouched = new SplObjectStorage();
        $this->linksTouched = new SplObjectStorage();
        $this->linksDeleted = new SplObjectStorage();

        // En workers es mejor no cachear endpoints para siempre
        $this->cachedPostEndpoint = null;
        $this->cachedDeleteEndpoint = null;
        $this->endpointsLoaded = false;
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    private function isLinkBeingDeleted(PmsEventoBeds24Link $link): bool
    {
        return $this->linksDeleted->contains($link) || $link->getStatus() === PmsEventoBeds24Link::STATUS_PENDING_DELETE;
    }

    private function ensureBookIdForDelete(PmsEventoBeds24Link $link, EntityManagerInterface $em, UnitOfWork $uow): void
    {
        if ($link->getBeds24BookId()) {
            return;
        }

        $recovered = $this->recoverBookIdFromQueues($link);
        if (!$recovered) {
            return;
        }

        $link->setBeds24BookId($recovered);

        $uow->recomputeSingleEntityChangeSet(
            class: $em->getClassMetadata(PmsEventoBeds24Link::class),
            entity: $link
        );
    }

    private function recoverBookIdFromQueues(PmsEventoBeds24Link $link): ?string
    {
        foreach ($link->getQueues() as $q) {
            if ($q->getBeds24BookIdOriginal()) {
                return (string) $q->getBeds24BookIdOriginal();
            }
        }
        return null;
    }

    private function pickBestLink(PmsEventoBeds24Link $a, PmsEventoBeds24Link $b): PmsEventoBeds24Link
    {
        if ($a->isEsPrincipal() !== $b->isEsPrincipal()) {
            return $a->isEsPrincipal() ? $a : $b;
        }

        if ((bool) $a->getBeds24BookId() !== (bool) $b->getBeds24BookId()) {
            return $a->getBeds24BookId() ? $a : $b;
        }

        return $a;
    }

    private function shouldIgnoreReservaUpdate(PmsReserva $reserva, UnitOfWork $uow): bool
    {
        if (!$reserva->isDatosLocked()) {
            return false;
        }

        $changeSet = $uow->getEntityChangeSet($reserva);
        $relevantChanges = array_diff(array_keys($changeSet), self::IGNORED_FIELDS_ON_LOCKED_OTA);

        return count($relevantChanges) === 0;
    }

    private function loadEndpoints(EntityManagerInterface $em): void
    {
        if ($this->endpointsLoaded) {
            return;
        }

        $repo = $em->getRepository(Beds24Endpoint::class);

        $this->cachedPostEndpoint = $repo->findOneBy([
            'accion' => self::ENDPOINT_POST_BOOKINGS,
            'activo' => true,
        ]);

        $this->cachedDeleteEndpoint = $repo->findOneBy([
            'accion' => self::ENDPOINT_DELETE_BOOKINGS,
            'activo' => true,
        ]);

        $this->endpointsLoaded = true;
    }

    private function collect(object $e): void
    {
        if ($e instanceof PmsEventoCalendario) {
            $this->eventosTouched->attach($e);
            return;
        }

        if ($e instanceof PmsReserva) {
            $this->reservasTouched->attach($e);
            return;
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

    private function isClean(): bool
    {
        return $this->eventosTouched->count() === 0
            && $this->reservasTouched->count() === 0
            && $this->linksTouched->count() === 0
            && $this->linksDeleted->count() === 0;
    }
}