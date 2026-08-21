<?php

declare(strict_types=1);

namespace App\Message\Factory;

use App\Message\Entity\Message;
use App\Message\Entity\MessageChannel;
use App\Message\Entity\MessageConversation;
use App\Message\Service\MessageDataResolverRegistry;
use Doctrine\ORM\EntityManagerInterface;

readonly class MessageFactory
{
    public function __construct(
        private EntityManagerInterface      $em,
        private MessageDataResolverRegistry $resolverRegistry // 🔥 Inyectado
    ) {}

    public function createForUiNew(?MessageConversation $conversation = null): Message
    {
        $message = new Message();
        $message->setDirection(Message::DIRECTION_OUTGOING);
        $message->setStatus(Message::STATUS_PENDING);

        // 🔥 LOGICA PARA OCULTAR BEDS24 EN Directas
        $isDirect = true;
        if ($conversation !== null) {
            $message->setConversation($conversation);
            $resolver = $this->resolverRegistry->getResolver($conversation->getContextType());
            $meta = $resolver ? $resolver->getMetadata($conversation->getContextId()) : [];

            // La misma pregunta que hace `Beds24SendEnqueuer`, con la misma respuesta y del
            // mismo sitio: la trae el dominio ya resuelta en `es_plataforma`. El núcleo no sabe
            // qué canales existen ni cuáles son nuestros — y no tiene por qué.
            $isDirect = ($meta['es_plataforma'] ?? false) !== true;
        }

        $activeChannels = $this->em->getRepository(MessageChannel::class)->findBy(['isActive' => true]);
        $channelIds = [];

        foreach ($activeChannels as $ch) {
            $chId = (string) $ch->getId();

            // Si es Beds24 y la reserva es directa, no lo marcamos
            if ($chId === 'beds24' && $isDirect) {
                continue;
            }
            $channelIds[] = $chId;
        }

        $message->setTransientChannels($channelIds);

        return $message;
    }

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
     * @param list<string> $targetChannels Códigos de canal a los que forzar el envío.
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