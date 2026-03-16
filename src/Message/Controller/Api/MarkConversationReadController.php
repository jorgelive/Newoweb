<?php

declare(strict_types=1);

namespace App\Message\Controller\Api;

use App\Message\Entity\Message;
use App\Message\Entity\MessageConversation;
use App\Message\Service\MessageDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[AsController]
final class MarkConversationReadController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageDispatcher $dispatcher
    ) {}

    public function __invoke(string $id): JsonResponse
    {
        $conversation = $this->em->getRepository(MessageConversation::class)->find($id);

        if (!$conversation) {
            throw new NotFoundHttpException('Conversación no encontrada.');
        }

        // 1. Buscamos todos los mensajes ENTRANTES que aún estén como RECEIVED
        $unreadMessages = $this->em->getRepository(Message::class)->findBy([
            'conversation' => $conversation,
            'direction'    => Message::DIRECTION_INCOMING,
            'status'       => Message::STATUS_RECEIVED
        ]);

        $nowUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $notifiedCount = 0;

        foreach ($unreadMessages as $msg) {
            // 2. Cambiamos estado local y guardamos metadata de lectura
            $msg->setStatus(Message::STATUS_READ);
            $msg->addBeds24Metadata('read', true);
            $msg->setBeds24ReadAt($nowUtc);

            $channel = $msg->getChannel();

            if ($channel) {
                // =========================================================================
                // 🔥 ARQUITECTURA: GENERACIÓN PROACTIVA DE COLAS (OUTBOX PATTERN)
                // =========================================================================
                // Nota de Diseño: La generación de colas de envío normalmente es Reactiva
                // (manejada por MessageEntityListener en el prePersist de mensajes nuevos).
                // Sin embargo, como aquí estamos haciendo un UPDATE a un mensaje INCOMING
                // existente, el Listener ignorará el cambio para envío.
                //
                // Por lo tanto, aquí adoptamos un enfoque PROACTIVO: Inyectamos el canal
                // original temporalmente y llamamos al Dispatcher de forma manual para
                // obligar al sistema a crear un recibo de lectura hacia la OTA/Proveedor.
                // =========================================================================

                $msg->setTransientChannels([(string)$channel->getId()]);

                $queues = $this->dispatcher->dispatch($msg);

                foreach ($queues as $queue) {
                    $this->em->persist($queue);
                }

                $notifiedCount++;
            }
        }

        // 3. Reseteamos el contador global de la conversación en la UI
        $conversation->resetUnreadCount();

        $this->em->flush();

        return new JsonResponse([
            'success' => true,
            'marked_messages' => count($unreadMessages),
            'receipts_queued' => $notifiedCount
        ]);
    }
}