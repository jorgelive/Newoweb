<?php

declare(strict_types=1);

namespace App\Message\DispatchHandler;

use App\Message\Dispatch\EnviarPushConversacionDispatch;
use App\Message\Entity\MessageConversation;
use App\Message\Service\Push\NotificadorPushConversacion;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class EnviarPushConversacionDispatchHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private NotificadorPushConversacion $notificador
    ) {}

    public function __invoke(EnviarPushConversacionDispatch $dispatch): void
    {
        $conversacion = $this->em->getRepository(MessageConversation::class)->find($dispatch->conversationId);

        if (!$conversacion instanceof MessageConversation) {
            return;
        }

        // Imprescindible: el worker es de vida larga y pudo cachear la conversación de un
        // trabajo anterior. Sin refresh se leería el `resumenIa` viejo y volveríamos al
        // bug del resumen con un turno de retraso, que es justo lo que esto arregla.
        $this->em->refresh($conversacion);

        // Si mientras tanto alguien abrió el chat y lo dio por leído, ya no hay nada que
        // avisar: la notificación llegaría para algo que el operador acaba de atender.
        if ($conversacion->getUnreadCount() === 0) {
            return;
        }

        $this->notificador->notificar($conversacion);
    }
}
