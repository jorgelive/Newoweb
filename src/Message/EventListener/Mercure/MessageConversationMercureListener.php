<?php

declare(strict_types=1);

namespace App\Message\EventListener\Mercure;

use App\Message\Entity\MessageConversation;
use App\Message\Service\Mercure\MercureBroadcaster;
use App\Message\Service\NoLeidos\ResumenNoLeidosService;
use App\Message\Service\Resumen\ResumenConversacionService;
use App\Service\WebPushNotificationService;
use App\Repository\UserRepository;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Throwable;

#[AsEntityListener(event: Events::postPersist, method: 'postPersist', entity: MessageConversation::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'postUpdate', entity: MessageConversation::class)]
readonly class MessageConversationMercureListener
{
    /**
     * @param MercureBroadcaster $mercureBroadcaster Emisor de eventos SSE en tiempo real.
     * @param WebPushNotificationService $pushService Servicio de despacho de notificaciones OS nativas.
     * @param UserRepository $userRepository Repositorio de usuarios.
     * @param LoggerInterface $logger Registro de trazas para depuración en Workers.
     * @param RoleHierarchyInterface $roleHierarchy Servicio core de Symfony para resolver herencia (security.yaml).
     */
    public function __construct(
        private MercureBroadcaster $mercureBroadcaster,
        private WebPushNotificationService $pushService,
        private UserRepository $userRepository,
        private LoggerInterface $logger,
        private RoleHierarchyInterface $roleHierarchy,
        private ResumenNoLeidosService $resumenNoLeidos,
        private ResumenConversacionService $resumenConversacion
    ) {}

    /**
     * Se ejecuta cuando se crea una nueva conversación en la base de datos.
     * * @param MessageConversation $conversation La entidad recién creada.
     * @param PostPersistEventArgs $event Argumentos del evento de Doctrine.
     */
    public function postPersist(MessageConversation $conversation, PostPersistEventArgs $event): void
    {
        // 1. Siempre transmitimos a Mercure primero
        $this->mercureBroadcaster->broadcastConversationUpdate($conversation, 'conversation_created');

        // 2. Si la conversación nace ya con mensajes sin leer, disparamos Push
        if ($conversation->getUnreadCount() > 0) {
            $this->safeDispatchPushNotifications($conversation);
        }
    }

    /**
     * Se ejecuta cuando se actualiza una conversación existente.
     * * @param MessageConversation $conversation La entidad actualizada.
     * @param PostUpdateEventArgs $event Argumentos del evento, útiles para el ChangeSet.
     */
    public function postUpdate(MessageConversation $conversation, PostUpdateEventArgs $event): void
    {
        // 1. Siempre transmitimos a Mercure primero
        $this->mercureBroadcaster->broadcastConversationUpdate($conversation, 'conversation_updated');

        // 2. Extraemos el ChangeSet para saber qué columnas físicas cambiaron
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
     * Envuelve el envío masivo en un try-catch y resuelve los roles en memoria (Security).
     * Garantiza que un error en el servicio Push no rompa el flujo asíncrono.
     * * @param MessageConversation $conversation La conversación que generó la alerta.
     */
    private function safeDispatchPushNotifications(MessageConversation $conversation): void
    {
        try {
            $this->logger->info("🔍 [Listener] Intentando disparar Push para la conversación ID: " . $conversation->getId());

            // 1. Traemos a los usuarios (Si tienes miles de huéspedes, idealmente cambiar esto por un método
            // que solo traiga al staff, ej: $this->userRepository->findStaffUsers())
            $allUsers = $this->userRepository->findAll();
            $eligibleUsers = [];

            // 2. Filtramos utilizando la jerarquía de roles de security.yaml
            foreach ($allUsers as $user) {
                // getReachableRoleNames toma los roles crudos de la BD y los expande según tu security.yaml
                $reachableRoles = $this->roleHierarchy->getReachableRoleNames($user->getRoles());

                // Evaluamos si dentro de todos sus roles (heredados o directos) tiene el permiso requerido
                if (in_array('ROLE_MENSAJES_SHOW', $reachableRoles, true)) {
                    $eligibleUsers[] = $user;
                }
            }

            $this->logger->info("👥 [Listener] Usuarios elegibles encontrados vía Security Hierarchy: " . count($eligibleUsers));

            if (empty($eligibleUsers)) {
                return;
            }

            $guestName = $conversation->getGuestName() ?? 'Huésped';

            // El cuerpo dice QUÉ quieren, no que «hay un mensaje nuevo»: con el resumen
            // IA el operador decide desde la barra de notificaciones si hace falta abrir
            // el chat. Si aún no hay resumen —recién llegado, IA apagada o el motor
            // falló— cae al texto del último mensaje, que sigue siendo más útil que la
            // frase genérica de antes.
            $noLeidos = $conversation->getUnreadCount();
            $cuerpo = $conversation->getResumenIa() ?: $this->resumenConversacion->textoDeRespaldo($conversation);

            if ($cuerpo === null || $cuerpo === '') {
                $cuerpo = 'Nuevo mensaje en la conversación de ' . ($conversation->getContextOrigin() ?? 'Chat');
            } elseif ($noLeidos > 1) {
                $cuerpo = "{$noLeidos} sin leer — {$cuerpo}";
            }

            $payload = [
                'title' => "Mensaje de {$guestName}",
                'body'  => $cuerpo,
                'type'  => 'info',
                // ✅ CAMBIO CLAVE: Usamos un query param para que Vue lo auto-seleccione
                'actionUrl' => "/chat?id={$conversation->getId()}",

                // Total de mensajes sin leer del sistema, para que el service
                // worker pinte el badge del icono con la app CERRADA.
                //
                // Sin esto, Android solo sabe contar notificaciones visibles, y
                // como se agrupan por conversación, el icono se quedaba clavado en
                // 1 aunque hubiera dieciséis mensajes esperando. El número no lo
                // puede calcular el service worker: no tiene sesión ni acceso a la
                // API, así que viaja en el propio push.
                'unreadTotal' => $this->resumenNoLeidos->total(),
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