<?php

declare(strict_types=1);

namespace App\Message\EventListener\Queue;

use App\Message\Contract\MessageQueueItemInterface;
use App\Message\Entity\Message;
use App\Message\Service\Queue\MessageDispatcher;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Listener encargado de orquestar el ciclo de vida completo de las colas de envío (Outbox Pattern).
 * Desacopla la infraestructura (colas) de la lógica de negocio (MessageRuleEngine).
 * Actúa como ÚNICO punto de creación, modificación, reprogramación y cancelación de colas.
 *
 * 🔥 ES AGNÓSTICO AL CANAL. No nombra ni Beds24 ni WhatsApp: reparte por
 * `MessageQueueItemInterface::getChannelId()` e itera `Message::getAllQueues()`. Añadir un
 * channel manager nuevo NO exige tocar este archivo. Ver docs/Mensajeria.md §5.
 */
#[AsEntityListener(event: Events::prePersist, method: 'prePersist', entity: Message::class)]
#[AsEntityListener(event: Events::preUpdate, method: 'preUpdate', entity: Message::class)]
readonly class MessageEnqueuerEntityListener
{
    /** Estados de cola sobre los que todavía se puede actuar (reprogramar o cancelar). */
    private const array ESTADOS_VIVOS = ['pending', 'queued'];

    public function __construct(
        private MessageDispatcher      $dispatcher,
        private EntityManagerInterface $em,
    ) {}

    /**
     * Intercepta la creación de un nuevo mensaje antes de que sea insertado en la base de datos.
     * Evalúa los canales requeridos y fabrica las entidades de cola iniciales correspondientes.
     *
     * @param Message $message La entidad mensaje que está siendo creada.
     * @param PrePersistEventArgs $event Los argumentos del evento proporcionados por Doctrine.
     */
    public function prePersist(Message $message, PrePersistEventArgs $event): void
    {
        // =========================================================================
        // 🔥 ADVERTENCIA ARQUITECTÓNICA (NO MODIFICAR ESTA CONDICIÓN) 🔥
        // =========================================================================
        // Este Listener SOLO reacciona a mensajes SALIENTES (outgoing).
        //
        // ¿Por qué no reaccionamos a mensajes INCOMING (entrantes)?
        // No vayas a pensar que nos olvidamos de generar los recibos de lectura (Read Receipts)
        // para Beds24 o WhatsApp al recibir un Webhook. ¡Está hecho a propósito!
        //
        // Si hiciéramos eso aquí (de forma Reactiva), crearíamos un efecto cascada
        // (Event Cascade) y bucles infinitos en la base de datos.
        // La generación de colas para marcar como leído se hace de forma PROACTIVA
        // y manual directamente dentro del controlador `MarkConversationReadController`.
        // =========================================================================

        if ($message->getDirection() === Message::DIRECTION_OUTGOING
            && in_array($message->getStatus(), [Message::STATUS_PENDING, Message::STATUS_QUEUED], true)) {

            // El dispatcher evalúa los 'transientChannels' y genera las entidades de cola
            $this->fabricarColas($message);
        }
    }

    /**
     * Intercepta la modificación de un mensaje existente en la base de datos.
     * Muta las colas existentes (reprogramar/cancelar) o fabrica nuevas si se añadieron canales.
     *
     * @param Message $message La entidad mensaje modificada.
     * @param PreUpdateEventArgs $event Contiene el ChangeSet con los campos alterados.
     */
    public function preUpdate(Message $message, PreUpdateEventArgs $event): void
    {
        if ($message->getDirection() !== Message::DIRECTION_OUTGOING) {
            return;
        }

        $queuesToRecompute = [];

        // 1. CASCADA DE CANCELACIÓN TOTAL
        if ($event->hasChangedField('status') && $message->getStatus() === Message::STATUS_CANCELLED) {
            foreach ($this->colasVivas($message) as $queue) {
                $queue->setStatus('cancelled');
                $queuesToRecompute[] = $queue;
            }
        } else {
            // 2. CASCADA DE REPROGRAMACIÓN (Last-Minute Booking o cambios de fecha)
            if ($event->hasChangedField('scheduledAt') && $message->getScheduledAt() !== null) {
                foreach ($this->colasVivas($message) as $queue) {
                    $queue->setRunAt($message->getScheduledAt());
                    $queuesToRecompute[] = $queue;
                }
            }

            // 3. LIMPIEZA DE HUÉRFANOS (Diffing de canales cancelados parcialmente)
            //
            // Antes había un `if (!in_array('beds24', …))` y otro para 'whatsapp_meta', escritos
            // a mano: el canal N+1 exigía añadir un bloque, y olvidarlo NO fallaba — simplemente
            // no cancelaba, y el mensaje acababa saliendo por un canal ya descartado.
            $requestedChannels = $message->getTransientChannels();
            if (!empty($requestedChannels) && in_array($message->getStatus(), [Message::STATUS_PENDING, Message::STATUS_QUEUED], true)) {
                foreach ($this->colasVivas($message) as $queue) {
                    if (!in_array($queue->getChannelId(), $requestedChannels, true)) {
                        $queue->setStatus('cancelled');
                        $queuesToRecompute[] = $queue;
                    }
                }
            }

            // 4. FABRICACIÓN DE NUEVOS CANALES
            if (in_array($message->getStatus(), [Message::STATUS_PENDING, Message::STATUS_QUEUED], true)) {
                $this->fabricarColas($message);
            }
        }

        // Obligamos a Doctrine a registrar los UPDATEs de las colas modificadas
        // ya que estamos dentro del evento preUpdate del padre.
        $uow = $this->em->getUnitOfWork();
        foreach ($queuesToRecompute as $mutatedQueue) {
            $this->em->persist($mutatedQueue);
            $uow->computeChangeSet($this->em->getClassMetadata(get_class($mutatedQueue)), $mutatedQueue);
        }
    }

    /**
     * Pide colas al dispatcher y las engancha al mensaje.
     *
     * El reparto por canal lo resuelve `Message::addQueue()`, que es el único punto del sistema
     * que conoce las clases concretas de cola.
     */
    private function fabricarColas(Message $message): void
    {
        foreach ($this->dispatcher->dispatch($message) as $queue) {
            // Sincronización estricta de memoria (Previene la "Amnesia de Doctrine")
            $message->addQueue($queue);
            $this->em->persist($queue);
        }
    }

    /**
     * Colas sobre las que todavía se puede actuar, en cualquier canal.
     *
     * @return list<MessageQueueItemInterface>
     */
    private function colasVivas(Message $message): array
    {
        $vivas = [];

        foreach ($message->getAllQueues() as $queue) {
            if ($queue instanceof MessageQueueItemInterface
                && in_array($queue->getStatus(), self::ESTADOS_VIVOS, true)) {
                $vivas[] = $queue;
            }
        }

        return $vivas;
    }
}
