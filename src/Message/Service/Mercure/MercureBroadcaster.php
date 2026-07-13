<?php

declare(strict_types=1);

namespace App\Message\Service\Mercure;

use ApiPlatform\Metadata\IriConverterInterface; // API Platform 3.x (en 2.x: ApiPlatform\Api\IriConverterInterface)
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
 * FIX: los DTOs ya no inventan el IRI; se lo pedimos a API Platform aquí,
 * garantizando que el "@id" de Mercure sea idéntico al de la API REST.
 */
class MercureBroadcaster
{
    public const string TOPIC_GLOBAL_RADAR = 'https://openperu.pe/host/conversations';

    public function __construct(
        private readonly HubInterface $hub,
        private readonly IriConverterInterface $iriConverter, // FIX
        private readonly LoggerInterface $logger
    ) {}

    public function broadcastMessage(Message $message): void
    {
        try {
            $topic = sprintf('https://openperu.pe/conversations/%s', $message->getConversation()->getId());

            // FIX: IRI real del recurso (mismo que devuelve el endpoint REST)
            $dto = new MercureMessageDto($message, $this->resolveIri($message));

            $payload = json_encode($dto, JSON_THROW_ON_ERROR);

            $this->hub->publish(new Update($topic, $payload));
        } catch (Throwable $e) {
            $this->logger->error('Error publicando Mensaje en Mercure: ' . $e->getMessage());
        }
    }

    public function broadcastConversationUpdate(MessageConversation $conversation, string $eventType = 'conversation_updated'): void
    {
        try {
            // FIX: IRI real del recurso
            $dto = new MercureConversationDto($conversation, $eventType, $this->resolveIri($conversation));

            $payload = json_encode($dto, JSON_THROW_ON_ERROR);

            $this->hub->publish(new Update(self::TOPIC_GLOBAL_RADAR, $payload));
        } catch (Throwable $e) {
            $this->logger->error('Error publicando Conversación en Mercure: ' . $e->getMessage());
        }
    }

    /**
     * Obtiene el IRI canónico desde API Platform.
     * Si la entidad no es un ApiResource expuesto (o falla la resolución),
     * devolvemos null y el DTO usa su fallback — nunca rompemos la publicación.
     */
    private function resolveIri(object $entity): ?string
    {
        try {
            return $this->iriConverter->getIriFromResource($entity);
        } catch (Throwable $e) {
            $this->logger->warning(sprintf(
                'No se pudo resolver IRI para %s: %s',
                $entity::class,
                $e->getMessage()
            ));
            return null;
        }
    }
}
