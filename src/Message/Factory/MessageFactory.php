<?php

declare(strict_types=1);

namespace App\Message\Factory;

use App\Message\Entity\Message;
use App\Message\Entity\MessageChannel;
use App\Message\Entity\MessageConversation;
use Doctrine\ORM\EntityManagerInterface;

class MessageFactory
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * Crea un mensaje base para la Interfaz (EasyAdmin).
     * Pre-selecciona todos los canales activos por defecto.
     */
    public function createForUiNew(): Message
    {
        $message = new Message();
        $message->setDirection(Message::DIRECTION_OUTGOING);
        $message->setStatus(Message::STATUS_PENDING);

        // Fan-Out: Seleccionamos todos los canales activos por defecto
        $activeChannels = $this->em->getRepository(MessageChannel::class)->findBy(['isActive' => true]);
        $channelIds = array_map(fn(MessageChannel $ch) => (string) $ch->getId(), $activeChannels);
        $message->setTransientChannels($channelIds);

        return $message;
    }

    /**
     * Crea una respuesta base para la Interfaz (EasyAdmin).
     * Restringe los canales solo al canal de origen del mensaje entrante.
     */
    public function createForUiReply(Message $incomingMessage): Message
    {
        $message = new Message();
        $message->setConversation($incomingMessage->getConversation());
        $message->setDirection(Message::DIRECTION_OUTGOING);
        $message->setStatus(Message::STATUS_PENDING);

        if ($incomingMessage->getChannel()) {
            $message->setTransientChannels([(string) $incomingMessage->getChannel()->getId()]);
        }

        return $message;
    }

    /**
     * Crea un mensaje directo vía Código/API (Procesos automáticos).
     */
    public function createOutboundProgrammatic(
        MessageConversation $conversation,
        string $content,
        array $targetChannels = []
    ): Message {
        $message = new Message();
        $message->setConversation($conversation);
        $message->setContentExternal($content);
        $message->setDirection(Message::DIRECTION_OUTGOING);
        $message->setStatus(Message::STATUS_PENDING);
        $message->setTransientChannels($targetChannels);

        return $message;
    }
}