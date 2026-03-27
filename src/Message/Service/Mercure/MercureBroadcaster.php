<?php

declare(strict_types=1);

namespace App\Message\Service\Mercure;

use App\Message\Dto\Mercure\MercureConversationDto;
use App\Message\Dto\Mercure\MercureMessageDto;
use App\Message\Entity\Message;
use App\Message\Entity\MessageConversation;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Throwable;

/**
 * Servicio centralizado para emitir eventos de Mercure.
 * Desacopla la lógica de publicación del resto de la aplicación y utiliza DTOs estandarizados.
 */
class MercureBroadcaster
{
    public const string TOPIC_GLOBAL_RADAR = 'https://openperu.pe/host/conversations';

    public function __construct(
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Emite un mensaje individual al tópico específico de una conversación.
     * Utilizado para actualizar el chat activo en Vue.
     */
    public function broadcastMessage(Message $message): void
    {
        try {
            $topic = sprintf('https://openperu.pe/conversations/%s', $message->getConversation()->getId());
            $dto = new MercureMessageDto($message);

            // json_encode manejará automáticamente la serialización gracias a JsonSerializable en el DTO
            $payload = json_encode($dto, JSON_THROW_ON_ERROR);

            $this->hub->publish(new Update($topic, $payload));
        } catch (Throwable $e) {
            $this->logger->error('Error publicando Mensaje en Mercure: ' . $e->getMessage());
        }
    }

    /**
     * Emite una actualización de estado de una conversación al radar global.
     * Utilizado para actualizar la barra lateral de Vue (Inbox).
     */
    public function broadcastConversationUpdate(MessageConversation $conversation, string $eventType = 'conversation_updated'): void
    {
        try {
            $dto = new MercureConversationDto($conversation, $eventType);
            $payload = json_encode($dto, JSON_THROW_ON_ERROR);

            $this->hub->publish(new Update(self::TOPIC_GLOBAL_RADAR, $payload));
        } catch (Throwable $e) {
            $this->logger->error('Error publicando Conversación en Mercure: ' . $e->getMessage());
        }
    }
}