<?php

declare(strict_types=1);

namespace App\Message\EventListener\Mercure;

use App\Message\Entity\MessageConversation;
use App\Message\Service\Mercure\MercureBroadcaster;
use App\Service\WebPushNotificationService;
use App\Repository\UserRepository;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Throwable;

#[AsEntityListener(event: Events::postPersist, method: 'postPersist', entity: MessageConversation::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'postUpdate', entity: MessageConversation::class)]
readonly class MessageConversationMercureListener
{
    public function __construct(
        private MercureBroadcaster $mercureBroadcaster,
        private WebPushNotificationService $pushService,
        private UserRepository $userRepository,
        private LoggerInterface $logger // ✅ Inyectamos el Logger para la trazabilidad
    ) {}

    public function postPersist(MessageConversation $conversation, PostPersistEventArgs $event): void
    {
        // 1. Siempre transmitimos a Mercure primero
        $this->mercureBroadcaster->broadcastConversationUpdate($conversation, 'conversation_created');

        // 2. Si la conversación nace ya con mensajes sin leer, disparamos Push
        if ($conversation->getUnreadCount() > 0) {
            $this->safeDispatchPushNotifications($conversation);
        }
    }

    public function postUpdate(MessageConversation $conversation, PostUpdateEventArgs $event): void
    {
        // 1. Siempre transmitimos a Mercure primero
        $this->mercureBroadcaster->broadcastConversationUpdate($conversation, 'conversation_updated');

        // 2. Verificamos si cambió el unreadCount
        $unitOfWork = $event->getObjectManager()->getUnitOfWork();
        $changeSet = $unitOfWork->getEntityChangeSet($conversation);

        if (isset($changeSet['unreadCount'])) {
            $oldUnread = (int) $changeSet['unreadCount'][0];
            $newUnread = (int) $changeSet['unreadCount'][1];

            // Si el número aumentó, es un mensaje nuevo. Disparamos Push.
            if ($newUnread > $oldUnread) {
                $this->safeDispatchPushNotifications($conversation);
            }
        }
    }

    /**
     * Envuelve el envío masivo en un try-catch.
     * Garantiza que un error en el servicio Push no rompa el flujo principal de Doctrine.
     */
    private function safeDispatchPushNotifications(MessageConversation $conversation): void
    {
        try {
            $this->logger->info("🔍 [Listener] Intentando disparar Push para la conversación ID: " . $conversation->getId());

            // Usamos ROLE_MENSAJES_SHOW como base para el equipo.
            $eligibleUsers = $this->userRepository->findByRole('ROLE_MENSAJES_SHOW');
            $this->logger->info("👥 [Listener] Usuarios elegibles encontrados con rol ROLE_MENSAJES_SHOW: " . count($eligibleUsers));

            $guestName = $conversation->getGuestName() ?? 'Huésped';

            $payload = [
                'title' => "Mensaje de {$guestName}",
                'body'  => "Nuevo mensaje en la conversación de " . ($conversation->getContextOrigin() ?? 'Chat'),
                'type'  => 'info',
                // ✅ CAMBIO CLAVE: Usamos un query param para que Vue lo auto-seleccione
                'actionUrl' => "/chat?id={$conversation->getId()}"
            ];

            foreach ($eligibleUsers as $user) {
                $this->logger->info("📨 [Listener] Delegando envío a WebPushNotificationService para: " . $user->getUserIdentifier());
                $this->pushService->sendToUser($user, $payload);
            }

            $this->logger->info("✅ [Listener] Flujo de notificaciones finalizado exitosamente.");

        } catch (Throwable $e) {
            // Si cualquier cosa falla (método no encontrado, error HTTP, etc.), el Listener
            // captura la excepción aquí, la graba en el log y deja que Symfony termine la petición HTTP normal.
            $this->logger->error('🔥 [Listener] ERROR CRÍTICO al procesar WebPush: ' . $e->getMessage(), [
                'exception' => $e
            ]);
        }
    }
}