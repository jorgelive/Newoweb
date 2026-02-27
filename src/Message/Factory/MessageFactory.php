<?php

declare(strict_types=1);

namespace App\Message\Factory;

use App\Message\Entity\Message;
use App\Message\Entity\MessageConversation;

class MessageFactory
{
    /**
     * Crea un mensaje nuevo (Fan-out o plantilla).
     */
    public function createOutbound(
        MessageConversation $conversation,
        string $content,
        array $targetChannels = [],
        // ?MessageTemplate $template = null // Futuro
    ): Message {
        $message = new Message();
        $message->setConversation($conversation);
        $message->setContentExternal($content);
        $message->setDirection(Message::DIRECTION_OUTGOING);
        $message->setStatus(Message::STATUS_PENDING);
        $message->setTransientChannels($targetChannels);

        // Si hay plantilla, aquí aplicarías la lógica para ignorar el contenido manual.

        return $message;
    }

    /**
     * Crea una respuesta exacta a un mensaje entrante.
     */
    public function createReply(Message $incomingMessage, string $content): Message
    {
        $message = new Message();
        $message->setConversation($incomingMessage->getConversation());
        $message->setContentExternal($content);
        $message->setDirection(Message::DIRECTION_OUTGOING);
        $message->setStatus(Message::STATUS_PENDING);

        // El fan-out se reduce solo al canal por el que entró el mensaje
        if ($incomingMessage->getChannel()) {
            $message->setTransientChannels([(string) $incomingMessage->getChannel()->getId()]);
        }

        return $message;
    }
}