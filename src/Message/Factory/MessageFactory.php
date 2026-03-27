<?php

declare(strict_types=1);

namespace App\Message\Factory;

use App\Message\Entity\Message;
use App\Message\Entity\MessageChannel;
use App\Message\Entity\MessageConversation;
use App\Message\Service\MessageDataResolverRegistry;
use App\Pms\Entity\PmsChannel; // 🔥 IMPORTANTE
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

            $source = (string) ($meta['source'] ?? '');
            $canalesDirectos = [PmsChannel::CODIGO_DIRECTO, 'manual', 'web', ''];

            $isDirect = in_array($source, $canalesDirectos, true);
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