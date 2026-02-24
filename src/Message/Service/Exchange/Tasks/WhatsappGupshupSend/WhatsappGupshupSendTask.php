<?php

declare(strict_types=1);

namespace App\Message\Service\Exchange\Tasks\WhatsappGupshupSend;

use App\Exchange\Service\Context\SyncContext;
use App\Exchange\Service\Contract\ExchangeHandlerInterface;
use App\Exchange\Service\Contract\ExchangeQueueProviderInterface;
use App\Exchange\Service\Contract\ExchangeTaskInterface;
use App\Exchange\Service\Mapping\MappingStrategyInterface;

final readonly class WhatsappGupshupSendTask implements ExchangeTaskInterface
{
    public function __construct(
        private WhatsappGupshupSendQueueProvider $provider,
        private WhatsappGupshupSendHandler       $handler,
        private WhatsappGupshupSendMappingStrategy $strategy
    ) {}

    public static function getTaskName(): string
    {
        return 'whatsapp_gupshup_message_push';
    }

    public function getMaxBatchSize(): int
    {
        // Gupshup soporta batching, pero por seguridad y trazabilidad de IDs
        // recomendamos lotes pequeños o incluso 1 a 1 si la API es síncrona.
        return 10;
    }

    public function getSyncMode(): string
    {
        return SyncContext::MODE_PUSH;
    }

    public function getSyncProvider(): string
    {
        return 'whatsapp_gupshup';
    }

    public function getQueueProvider(): ExchangeQueueProviderInterface { return $this->provider; }
    public function getHandler(): ExchangeHandlerInterface { return $this->handler; }
    public function getMappingStrategy(): MappingStrategyInterface { return $this->strategy; }

    /**
     * ✅ IMPLEMENTACIÓN DEL PROXY
     * El Handler llama a esto -> Tarea llama a Provider -> Provider llama a Repository
     */
    public function getGroupingMetadata(array $ids): array
    {
        return $this->provider->getGroupingMetadata($ids);
    }
}