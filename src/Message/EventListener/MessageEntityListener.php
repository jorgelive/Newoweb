<?php

declare(strict_types=1);

namespace App\Message\EventListener;

use App\Message\Entity\Message;
use App\Message\Service\MessageDispatcher;
use App\Message\Service\MessageTranslator;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;

#[AsEntityListener(event: Events::prePersist, method: 'prePersist', entity: Message::class)]
#[AsEntityListener(event: Events::preUpdate, method: 'preUpdate', entity: Message::class)]
class MessageEntityListener
{
    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly MessageDispatcher $dispatcher,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Intercepta la creación de un nuevo mensaje antes de que sea insertado en la base de datos.
     *
     * Responsabilidades principales:
     * 1. Ejecutar el motor de traducción para asegurar que el contenido esté en el idioma correcto.
     * 2. Implementar el patrón Outbox generando las colas de envío (Beds24, WhatsApp).
     */
    public function prePersist(Message $message, PrePersistEventArgs $event): void
    {
        // 1. Procesar Traducciones Automáticas
        $this->translator->process($message);

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
            && $message->getStatus() === Message::STATUS_PENDING) {

            // El dispatcher evalúa los 'transientChannels' y genera las entidades de cola
            $queues = $this->dispatcher->dispatch($message);

            foreach ($queues as $queue) {
                // Persistimos las colas generadas. Doctrine las incluirá en la misma transacción del flush() actual,
                // garantizando el cumplimiento ACID (el mensaje y su cola se guardan juntos o no se guarda nada).
                $this->em->persist($queue);
            }
        }
    }

    /**
     * Intercepta la actualización de un mensaje existente antes de que los cambios se apliquen.
     */
    public function preUpdate(Message $message, PreUpdateEventArgs $event): void
    {
        // Si el contenido fue editado por algún proceso posterior, volvemos a asegurar
        // que las traducciones locales y externas estén sincronizadas.
        if ($event->hasChangedField('contentLocal') || $event->hasChangedField('contentExternal')) {
            $this->translator->process($message);
        }
    }
}