<?php

declare(strict_types=1);

namespace App\Message\EventListener\Queue;

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
 */
#[AsEntityListener(event: Events::prePersist, method: 'prePersist', entity: Message::class)]
#[AsEntityListener(event: Events::preUpdate, method: 'preUpdate', entity: Message::class)]
readonly class MessageEnqueuerEntityListener
{
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
            $queues = $this->dispatcher->dispatch($message);

            foreach ($queues as $queue) {
                // Sincronización estricta de memoria (Previene la "Amnesia de Doctrine")
                if (method_exists($message, 'addBeds24SendQueue') && str_contains(get_class($queue), 'Beds24')) {
                    $message->addBeds24SendQueue($queue);
                } elseif (method_exists($message, 'addWhatsappMetaSendQueue') && str_contains(get_class($queue), 'Whatsapp')) {
                    $message->addWhatsappMetaSendQueue($queue);
                }

                $this->em->persist($queue);
            }
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

        $uow = $this->em->getUnitOfWork();
        $queuesToRecompute = [];

        // 1. CASCADA DE CANCELACIÓN TOTAL
        if ($event->hasChangedField('status') && $message->getStatus() === Message::STATUS_CANCELLED) {
            foreach ($message->getBeds24SendQueues() as $queue) {
                if (in_array($queue->getStatus(), ['pending', 'queued'], true)) {
                    $queue->setStatus('cancelled');
                    $queuesToRecompute[] = $queue;
                }
            }
            foreach ($message->getWhatsappMetaSendQueues() as $queue) {
                if (in_array($queue->getStatus(), ['pending', 'queued'], true)) {
                    $queue->setStatus('cancelled');
                    $queuesToRecompute[] = $queue;
                }
            }
        }
        else {
            // 2. CASCADA DE REPROGRAMACIÓN (Last-Minute Booking o cambios de fecha)
            if ($event->hasChangedField('scheduledAt') && $message->getScheduledAt() !== null) {
                foreach ($message->getBeds24SendQueues() as $queue) {
                    if (in_array($queue->getStatus(), ['pending', 'queued'], true)) {
                        $queue->setRunAt($message->getScheduledAt());
                        $queuesToRecompute[] = $queue;
                    }
                }
                foreach ($message->getWhatsappMetaSendQueues() as $queue) {
                    if (in_array($queue->getStatus(), ['pending', 'queued'], true)) {
                        $queue->setRunAt($message->getScheduledAt());
                        $queuesToRecompute[] = $queue;
                    }
                }
            }

            // 3. LIMPIEZA DE HUÉRFANOS (Diffing de canales cancelados parcialmente)
            $requestedChannels = $message->getTransientChannels();
            if (!empty($requestedChannels) && in_array($message->getStatus(), [Message::STATUS_PENDING, Message::STATUS_QUEUED], true)) {

                if (!in_array('beds24', $requestedChannels, true)) {
                    foreach ($message->getBeds24SendQueues() as $queue) {
                        if (in_array($queue->getStatus(), ['pending', 'queued'], true)) {
                            $queue->setStatus('cancelled');
                            $queuesToRecompute[] = $queue;
                        }
                    }
                }

                if (!in_array('whatsapp_meta', $requestedChannels, true)) {
                    foreach ($message->getWhatsappMetaSendQueues() as $queue) {
                        if (in_array($queue->getStatus(), ['pending', 'queued'], true)) {
                            $queue->setStatus('cancelled');
                            $queuesToRecompute[] = $queue;
                        }
                    }
                }
            }

            // 4. FABRICACIÓN DE NUEVOS CANALES
            if (in_array($message->getStatus(), [Message::STATUS_PENDING, Message::STATUS_QUEUED], true)) {
                $newQueues = $this->dispatcher->dispatch($message);

                foreach ($newQueues as $queue) {
                    if (method_exists($message, 'addBeds24SendQueue') && str_contains(get_class($queue), 'Beds24')) {
                        $message->addBeds24SendQueue($queue);
                    } elseif (method_exists($message, 'addWhatsappMetaSendQueue') && str_contains(get_class($queue), 'Whatsapp')) {
                        $message->addWhatsappMetaSendQueue($queue);
                    }
                    $this->em->persist($queue);
                }
            }
        }

        // Obligamos a Doctrine a registrar los UPDATEs de las colas modificadas
        // ya que estamos dentro del evento preUpdate del padre.
        foreach ($queuesToRecompute as $mutatedQueue) {
            $this->em->persist($mutatedQueue);
            $uow->computeChangeSet($this->em->getClassMetadata(get_class($mutatedQueue)), $mutatedQueue);
        }
    }
}