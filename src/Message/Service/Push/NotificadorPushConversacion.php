<?php

declare(strict_types=1);

namespace App\Message\Service\Push;

use App\Message\Entity\Message;
use App\Message\Entity\MessageConversation;
use App\Message\Service\NoLeidos\ResumenNoLeidosService;
use App\Message\Service\Resumen\ResumenConversacionService;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Security\Roles;
use App\Service\WebPushNotificationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Throwable;

/**
 * Arma y despacha la notificación push de una conversación con mensajes nuevos.
 *
 * Vivía dentro de `MessageConversationMercureListener`, que es un listener de Doctrine.
 * Se sacó por dos motivos, y el segundo es el que se notaba:
 *
 * 1. Hacía peticiones HTTP a FCM/APNs **dentro de un flush**.
 * 2. Salía en el mismo instante en que entraba el mensaje, así que el resumen IA —que se
 *    calcula unos segundos después, tras la espera de ráfaga— **todavía no existía**: la
 *    notificación viajaba con el resumen del turno ANTERIOR. Ahora la dispara el mismo
 *    worker que acaba de escribir el resumen, así que siempre lleva el vigente.
 *
 * Ver docs/PwaNotificaciones.md.
 */
final readonly class NotificadorPushConversacion
{
    public function __construct(
        private EntityManagerInterface $em,
        private WebPushNotificationService $push,
        private UserRepository $usuarios,
        private RoleHierarchyInterface $jerarquia,
        private ResumenNoLeidosService $resumenNoLeidos,
        private ResumenConversacionService $resumenConversacion,
        private LoggerInterface $logger,
    ) {}

    public function notificar(MessageConversation $conversacion): void
    {
        try {
            $destinatarios = $this->destinatarios();

            if ($destinatarios === []) {
                return;
            }

            $payload = $this->payload($conversacion);

            foreach ($destinatarios as $usuario) {
                $this->push->sendToUser($usuario, $payload);
            }
        } catch (Throwable $e) {
            // Un aviso que no sale no puede tumbar el proceso que lo pidió.
            $this->logger->error('[PushConversacion] Fallo al notificar: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * Quien puede ver el chat, expandiendo la jerarquía de roles de `security.yaml`.
     *
     * @return list<\App\Entity\User>
     */
    private function destinatarios(): array
    {
        $elegibles = [];

        foreach ($this->usuarios->findAll() as $usuario) {
            $roles = $this->jerarquia->getReachableRoleNames($usuario->getRoles());

            if (in_array(Roles::MENSAJES_SHOW, $roles, true)) {
                $elegibles[] = $usuario;
            }
        }

        return $elegibles;
    }

    /**
     * ¿El último mensaje de la conversación lo escribió el agente?
     *
     * Se mira el ÚLTIMO mensaje y no «si existe alguna respuesta del sistema»: lo que
     * interesa es el estado actual. Si el huésped volvió a escribir después de que la IA
     * contestara, vuelve a haber algo pendiente y el aviso no debe decir que está
     * atendido.
     *
     * `SENDER_SYSTEM` es lo que pone `AiConversationProcessor::encolarRespuesta()`; un
     * operador escribiendo desde el panel deja `SENDER_HOST`, así que no se confunden.
     */
    private function laIaYaRespondio(MessageConversation $conversacion): bool
    {
        $ultimo = $this->em->getRepository(Message::class)->createQueryBuilder('m')
            ->where('m.conversation = :c')
            ->setParameter('c', $conversacion->getId(), 'uuid')
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $ultimo instanceof Message
            && $ultimo->getDirection() === Message::DIRECTION_OUTGOING
            && $ultimo->getSenderType() === Message::SENDER_SYSTEM;
    }

    /** @return array<string, mixed> */
    private function payload(MessageConversation $conversacion): array
    {
        $huesped = $conversacion->getGuestName() ?? 'Huésped';
        $noLeidos = $conversacion->getUnreadCount();

        // El cuerpo dice QUÉ quieren, no que «hay un mensaje nuevo». Si no hay resumen
        // —IA apagada, motor caído o conversación recién escalada sin mensaje entrante—
        // cae al texto del último mensaje, que sigue siendo mejor que la frase genérica.
        $cuerpo = $conversacion->getResumenIa() ?: $this->resumenConversacion->textoDeRespaldo($conversacion);

        if ($cuerpo === null || $cuerpo === '') {
            $cuerpo = 'Nuevo mensaje en la conversación de ' . ($conversacion->getContextOrigin() ?? 'Chat');
        } elseif ($noLeidos > 1) {
            $cuerpo = "{$noLeidos} sin leer — {$cuerpo}";
        }

        // Si el agente ya contestó, decirlo cambia la decisión del operador: no es lo
        // mismo «alguien espera respuesta» que «esto ya está atendido, échale un ojo».
        // El aviso se espera a propósito a que el agente termine para poder afirmarlo.
        if ($this->laIaYaRespondio($conversacion)) {
            $cuerpo = "🤖 Respondido por IA — {$cuerpo}";
        }

        return [
            'title'     => "Mensaje de {$huesped}",
            'body'      => $cuerpo,
            'type'      => 'info',
            'actionUrl' => "/chat?id={$conversacion->getId()}",

            // Total de no leídos del sistema, para que el service worker pinte el badge
            // con la app cerrada. En Android no surte efecto —la Badging API no existe
            // en Chrome móvil—, pero en escritorio sí. Ver docs/PwaNotificaciones.md §5.
            'unreadTotal' => $this->resumenNoLeidos->total(),
        ];
    }
}
