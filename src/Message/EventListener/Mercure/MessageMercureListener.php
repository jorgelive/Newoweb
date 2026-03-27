<?php

declare(strict_types=1);

namespace App\Message\EventListener\Mercure;

use App\Message\Entity\Message;
use App\Message\Service\Mercure\MercureBroadcaster;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Escucha automáticamente cualquier cambio físico en la base de datos
 * sobre los Mensajes individuales y los transmite por Mercure al panel de Vue.
 */
#[AsEntityListener(event: Events::postPersist, method: 'postPersist', entity: Message::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'postUpdate', entity: Message::class)]
readonly class MessageMercureListener
{
    public function __construct(
        private MercureBroadcaster $mercureBroadcaster
    ) {}

    /**
     * Se dispara cuando nace un NUEVO mensaje (Huésped escribe, o tú envías uno).
     */
    public function postPersist(Message $message, PostPersistEventArgs $event): void
    {
        $this->mercureBroadcaster->broadcastMessage($message);
    }

    /**
     * Se dispara cuando un mensaje CAMBIA de estado (Pasa a sent, delivered, read, failed)
     * o cuando se le inyecta nueva metadata desde los Webhooks.
     */
    public function postUpdate(Message $message, PostUpdateEventArgs $event): void
    {
        // Opcional: Podrías filtrar aquí para no emitir si solo cambió un campo
        // irrelevante, pero dado tu diseño reactivo, cualquier cambio en la entidad
        // Message es digno de reflejarse en la UI.
        $this->mercureBroadcaster->broadcastMessage($message);
    }
}