<?php

declare(strict_types=1);

namespace App\Message\EventListener\Mercure;

use App\Message\Entity\MessageConversation;
use App\Message\Service\Mercure\MercureBroadcaster;
use App\Service\WebPushNotificationService;
use App\Repository\UserRepository; // ✅ Necesitamos buscar usuarios
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;

#[AsEntityListener(event: Events::postPersist, method: 'postPersist', entity: MessageConversation::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'postUpdate', entity: MessageConversation::class)]
readonly class MessageConversationMercureListener
{
    public function __construct(
        private MercureBroadcaster $mercureBroadcaster,
        private WebPushNotificationService $pushService,
        private UserRepository $userRepository // ✅ Inyectamos el repositorio de usuarios
    ) {}

    public function postPersist(MessageConversation $conversation, PostPersistEventArgs $event): void
    {
        $this->mercureBroadcaster->broadcastConversationUpdate($conversation, 'conversation_created');

        if ($conversation->getUnreadCount() > 0) {
            $this->dispatchPushNotificationsToTeam($conversation);
        }
    }

    public function postUpdate(MessageConversation $conversation, PostUpdateEventArgs $event): void
    {
        $this->mercureBroadcaster->broadcastConversationUpdate($conversation, 'conversation_updated');

        $unitOfWork = $event->getObjectManager()->getUnitOfWork();
        $changeSet = $unitOfWork->getEntityChangeSet($conversation);

        if (isset($changeSet['unreadCount'])) {
            $oldUnread = (int) $changeSet['unreadCount'][0];
            $newUnread = (int) $changeSet['unreadCount'][1];

            if ($newUnread > $oldUnread) {
                $this->dispatchPushNotificationsToTeam($conversation);
            }
        }
    }

    /**
     * Envía la notificación Push a todo el equipo que tenga permisos de mensajes.
     */
    private function dispatchPushNotificationsToTeam(MessageConversation $conversation): void
    {
        // 1. Buscamos a todos los usuarios que tengan el rol necesario para ver mensajes.
        // Usamos ROLE_MENSAJES_SHOW como base.
        $eligibleUsers = $this->userRepository->findByRole('ROLE_MENSAJES_SHOW');

        $guestName = $conversation->getGuestName() ?? 'Huésped';

        $payload = [
            'title' => "Mensaje de {$guestName}",
            'body'  => "Nuevo mensaje en la conversación de " . ($conversation->getContextOrigin() ?? 'Chat'),
            'type'  => 'info',
            'actionUrl' => "/chat/{$conversation->getId()}"
        ];

        // 2. Notificamos a cada uno de los miembros del equipo.
        // WebPushNotificationService se encarga de ignorar a los que no tengan dispositivos suscritos.
        foreach ($eligibleUsers as $user) {
            $this->pushService->sendToUser($user, $payload);
        }
    }
}