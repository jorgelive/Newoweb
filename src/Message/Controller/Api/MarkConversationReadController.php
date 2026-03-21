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

            $channel = $msg->getChannel();

            if ($channel) {
                // Extraemos el ID natural del canal ('beds24', 'whatsapp_meta', etc.)
                $platformId = $channel->getId();

                // =========================================================================
                // LÓGICA ESPECÍFICA POR PLATAFORMA (Metadata Local)
                // =========================================================================

                if ($platformId === 'beds24') {
                    // Protegemos la lógica original de Beds24
                    $msg->addBeds24Metadata('read', true);
                    $msg->setBeds24ReadAt($nowUtc);

                } elseif ($platformId === 'whatsapp_meta') {
                    // Auditoría simétrica para Meta: Guardamos exactamente cuándo lo leyó tu equipo
                    $msg->setWhatsappMetaReadAt($nowUtc);
                    $msg->addWhatsappMetaMetadata('read_by_system', true);
                }

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

                $msg->setTransientChannels([(string)$platformId]);

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