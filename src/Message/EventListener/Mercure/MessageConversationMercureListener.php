<?php

declare(strict_types=1);

namespace App\Message\EventListener\Mercure;

use App\Message\Entity\MessageConversation;
use App\Message\Service\Mercure\MercureBroadcaster;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Escucha automáticamente cualquier cambio físico en la base de datos
 * sobre las conversaciones y lo transmite por Mercure al panel de Vue.
 */
#[AsEntityListener(event: Events::postPersist, method: 'postPersist', entity: MessageConversation::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'postUpdate', entity: MessageConversation::class)]
readonly class MessageConversationMercureListener
{
    public function __construct(
        private MercureBroadcaster $mercureBroadcaster
    ) {}

    /**
     * Se dispara cuando nace una NUEVA conversación (Ej: Un Walk-in de WhatsApp).
     */
    public function postPersist(MessageConversation $conversation, PostPersistEventArgs $event): void
    {
        $this->mercureBroadcaster->broadcastConversationUpdate($conversation, 'conversation_created');
    }

    /**
     * Se dispara cuando cambia ALGÚN DATO de una conversación existente
     * (Ej: lastMessageAt, unreadCount, status, o cambios del PMS).
     */
    public function postUpdate(MessageConversation $conversation, PostUpdateEventArgs $event): void
    {
        $this->mercureBroadcaster->broadcastConversationUpdate($conversation, 'conversation_updated');
    }
}