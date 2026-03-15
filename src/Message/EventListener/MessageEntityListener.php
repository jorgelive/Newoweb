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
     * * Responsabilidades principales:
     * 1. Ejecutar el motor de traducción para asegurar que el contenido (local/externo) esté en el idioma correcto.
     * 2. Implementar el patrón Outbox generando las colas de envío necesarias (Beds24, WhatsApp) si el mensaje es saliente.
     *
     * @param Message $message La entidad del mensaje que está a punto de ser persistida.
     * @param PrePersistEventArgs $event Argumentos del evento proporcionados por Doctrine.
     * * @throws \Exception Si el dispatcher falla al generar las colas de envío.
     * * @example
     * // Al hacer $em->persist($message); Doctrine llamará automáticamente a este método.
     * // Si $message->getDirection() === 'outgoing', se crearán y persistirán registros en WhatsappGupshupSendQueue.
     */
    public function prePersist(Message $message, PrePersistEventArgs $event): void
    {
        // 1. Procesar Traducciones Automáticas
        // Analiza el contexto de la plantilla y los idiomas objetivo para rellenar contentLocal y contentExternal.
        $this->translator->process($message);

        // 2. Patrón Outbox: Generar las colas de envío
        // Solo evaluamos mensajes que nosotros estamos enviando (outgoing) y que no han sido procesados aún (pending).
        if ($message->getDirection() === Message::DIRECTION_OUTGOING
            && $message->getStatus() === Message::STATUS_PENDING) {

            // El dispatcher evalúa los 'transientChannels' (ej: ['beds24', 'whatsapp_gupshup'])
            // y genera las entidades de cola correspondientes.
            $queues = $this->dispatcher->dispatch($message);

            foreach ($queues as $queue) {
                // Persistimos las colas generadas. Doctrine las incluirá en la misma transacción del flush() actual,
                // garantizando el cumplimiento ACID (el mensaje y su cola se guardan juntos o no se guarda nada).
                $this->em->persist($queue);
            }
        }
    }

    /**
     * Intercepta la actualización de un mensaje existente antes de que los cambios se apliquen en la base de datos.
     * * Responsabilidades principales:
     * 1. Re-evaluar las traducciones si los campos de contenido han sido modificados manualmente.
     *
     * @param Message $message La entidad del mensaje que está a punto de ser actualizada.
     * @param PreUpdateEventArgs $event Argumentos del evento que permiten inspeccionar qué campos cambiaron (hasChangedField).
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