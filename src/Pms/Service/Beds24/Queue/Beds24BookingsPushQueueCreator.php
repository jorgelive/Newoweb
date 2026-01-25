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

final class Beds24BookingsPushQueueCreator
{
    private const ENDPOINT_POST_BOOKINGS = 'POST_BOOKINGS';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SyncContext $syncContext,
    ) {}

    public function enqueueForLink(
        PmsEventoBeds24Link $link,
        PmsBeds24Endpoint $endpoint,
        ?UnitOfWork $uow = null
    ): void {

        // Si el contexto es 'push_beds24', significa que el Worker está
        // guardando el resultado de un éxito. NO debemos crear otra cola.
        if ($this->syncContext->isPush()) {
            return;
        }

        // [PROTECCIÓN DELETE] Detectamos si el link se está borrando en este Flush
        $isDelete = ($uow !== null && $uow->isScheduledForDelete($link));

        // [ESCUDO ANTI-FANTASMAS] 🛡️
        // Si no estamos borrando, verificamos que el Evento sea real.
        // Si el Evento es NUEVO (sin ID) y Doctrine NO planea guardarlo (isScheduledForInsert=false),
        // entonces es un "Fantasma" (creado y borrado en el mismo request). ABORTAMOS.
        $evento = $link->getEvento();
        if (!$isDelete && $evento && $uow) {
            $evtState = $uow->getEntityState($evento);
            if ($evtState === UnitOfWork::STATE_NEW && !$uow->isScheduledForInsert($evento)) {
                return; // 🛑 Stop: Evita el error "A new entity was found..."
            }
        }

        // 1. POLÍTICA GLOBAL PARA PULL
        if ($this->syncContext->isPull()) {
            if ($endpoint->getAccion() !== self::ENDPOINT_POST_BOOKINGS) {
                return;
            }
            if ($link->getBeds24BookId() !== null && $link->getBeds24BookId() !== '') {
                if (!$link->isMirror()) {
                    return; // ❌ ROOT nunca se actualiza en PULL
                }
            }
        }

        // 2. Guards
        // Si se está borrando, permitimos que 'evento' sea null para intentar salvar el ID del Link
        if (!$evento && !$isDelete) return;

        $map = $link->getUnidadBeds24Map();
        if ($map === null) return;

        // 3. Resolución Dedupe Key
        $isNewLink = ($link->getId() === null);
        $originLink = $link->getOriginLink();
        $originBookId = $originLink ? trim((string) $originLink->getBeds24BookId()) : null;

        if ($isNewLink && $this->syncContext->isPull() && !$originBookId) {
            return;
        }

        if ($isNewLink) {
            if ($this->syncContext->isPull()) {
                $mapKey = $map->getId() ?? ('obj_' . spl_object_id($map));
                $dedupeKey = sprintf('mirror:originBookId:%s:map:%s:endpoint:%s', $originBookId ?? 'null', $mapKey, $endpoint->getAccion());
            } else {
                // Si evento es null (en borrado extremo), usamos un random seguro
                $eventKey = $evento ? ($evento->getId() ?? ('new_evt_' . spl_object_id($evento))) : 'del_' . uniqid();
                $mapKey   = $map->getId() ?? ('new_map_' . spl_object_id($map));
                $dedupeKey = sprintf('ui:event:%s:map:%s:endpoint:%s', $eventKey, $mapKey, $endpoint->getAccion());
            }
        } else {
            $dedupeKey = sprintf('link:%d:endpoint:%s', $link->getId(), $endpoint->getAccion());
        }

        // 4. Payload Snapshot & Hash
        $payload = [
            'linkId'       => $link->getId() ?? ('tmp_' . spl_object_id($link)),
            'isMirror'     => $link->isMirror(),
            'eventoId'     => $evento?->getId() ?? 0,
            'inicio'       => $evento?->getInicio()?->format('c'),
            'fin'          => $evento?->getFin()?->format('c'),
            'estado'       => $evento?->getEstado()?->getCodigo(),
            'beds24RoomId' => $map->getBeds24RoomId(),
            'configId'     => $map->getBeds24Config()?->getId(),
        ];

        $reserva = $evento?->getReserva();
        if ($reserva !== null) {
            $datosLocked = (bool) ($reserva->isDatosLocked() ?? false);
            $payload['datosLocked'] = $datosLocked;
            $payload['cliente'] = ['canal' => $reserva->getChannel()?->getCodigo()];

            if (!$datosLocked) {
                $payload['cliente'] = array_merge($payload['cliente'], [
                    'nombre'    => $reserva->getNombreCliente(),
                    'apellido'  => $reserva->getApellidoCliente(),
                    'telefono'  => $reserva->getTelefono(),
                    'email'     => $reserva->getEmailCliente(),
                    'nota'      => $reserva->getNota(),
                ]);
            }
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $payloadHash = sha1((string) $json);

        // 5. Dedupe & Actualización (Idempotencia)
        // [PROTECCIÓN] Si se está borrando, NO iteramos la colección para no tocar proxies muertos
        if (!$isDelete) {
            foreach ($link->getQueues() as $queue) {
                if ($queue->getDedupeKey() !== $dedupeKey) continue;

                if ($queue->getPayloadHash() === $payloadHash && $queue->getStatus() === PmsBookingsPushQueue::STATUS_PENDING) {
                    return; // Ya está pendiente con el mismo contenido
                }

                // Reactivar o actualizar cola existente
                $queue
                    ->setBeds24Config($map->getBeds24Config())
                    ->setPayloadHash($payloadHash)
                    ->setStatus(PmsBookingsPushQueue::STATUS_PENDING)
                    ->setRunAt(new DateTimeImmutable()) // Programar para ejecución inmediata
                    ->setFailedReason(null)
                    ->setRetryCount(0)
                    ->setLockedAt(null)
                    ->setLockedBy(null)
                    ->setBeds24BookIdOriginal($link->getBeds24BookId());

                if ($uow !== null) {
                    $uow->recomputeSingleEntityChangeSet(
                        $this->em->getClassMetadata(PmsBookingsPushQueue::class),
                        $queue
                    );
                }
                return;
            }
        }

        // 6. Crear Nueva Fila (INSERT)
        $queue = new PmsBookingsPushQueue();

        // [CRÍTICO] setLink activa la captura de los IDs originales (tu setter defensivo).
        // Aunque el link se borre, la cola se queda con los datos.
        $queue->setLink($link);

        $queue
            ->setBeds24Config($map->getBeds24Config())
            ->setEndpoint($endpoint)
            ->setDedupeKey($dedupeKey)
            ->setPayloadHash($payloadHash)
            ->setStatus(PmsBookingsPushQueue::STATUS_PENDING)
            ->setRunAt(new DateTimeImmutable());
        // setBeds24BookIdOriginal ya fue seteado por setLink() internamente

        // [PROTECCIÓN DOCTRINE]
        // Si el link se está borrando, NO lo añadimos a la colección inversa.
        // Esto evita el error "New entity found" al modificar una entidad ScheduledForDelete.
        if (!$isDelete) {
            $link->addQueue($queue);
        }

        $this->em->persist($queue);

        if ($uow !== null) {
            $uow->computeChangeSet(
                $this->em->getClassMetadata(PmsBookingsPushQueue::class),
                $queue
            );
        }
    }

    /**
     * Cancela ("Borra lógicamente") las colas pendientes de tipo POST.
     * MEJORADO: Busca activamente en DB si la colección no está inicializada.
     */
    public function cancelPendingPostForLink(
        PmsEventoBeds24Link $link,
        ?string $reason = null,
        ?UnitOfWork $uow = null
    ): bool {
        $changed = false;

        // 1. Determinar qué colas revisar
        $queuesToCheck = [];
        $collection = $link->getQueues();

        // Si la colección ya está cargada en memoria, la usamos (es lo más rápido y seguro para Doctrine)
        if ($collection instanceof PersistentCollection && $collection->isInitialized()) {
            $queuesToCheck = $collection->toArray();
        }
        // Si NO está cargada y estamos borrando, NO invocamos $link->getQueues() ciegamente.
        // Hacemos una búsqueda dirigida en DB para atrapar las pendientes.
        elseif ($link->getId() !== null) {
            $queuesToCheck = $this->em->getRepository(PmsBookingsPushQueue::class)->findBy([
                'link'   => $link,
                'status' => PmsBookingsPushQueue::STATUS_PENDING
            ]);
        }

        foreach ($queuesToCheck as $queue) {
            if (!$queue instanceof PmsBookingsPushQueue) continue;

            // Si la cola misma se está borrando por cascada, la ignoramos
            if ($uow !== null && $uow->isScheduledForDelete($queue)) continue;

            if ($queue->getEndpoint()?->getAccion() !== self::ENDPOINT_POST_BOOKINGS) continue;

            // Validación redundante por si acaso trajimos algo que no era pending
            if (in_array($queue->getStatus(), [PmsBookingsPushQueue::STATUS_SUCCESS, PmsBookingsPushQueue::STATUS_CANCELLED], true)) {
                continue;
            }

            $queue->setStatus(PmsBookingsPushQueue::STATUS_CANCELLED)
                ->setFailedReason(sprintf('Cancelled: %s', $reason ?: 'manual cancel'))
                ->setRunAt(null)
                ->setLockedAt(null)
                ->setLockedBy(null);

            if ($uow !== null) {
                // Al traerlas con findBy o de la colección, Doctrine las conoce.
                // Usamos recomputeSingleEntityChangeSet porque estamos modificando
                // una entidad gestionada.
                $uow->recomputeSingleEntityChangeSet(
                    $this->em->getClassMetadata(PmsBookingsPushQueue::class),
                    $queue
                );
            }
            $changed = true;
        }

        return $changed;
    }
}