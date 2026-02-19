<?php


declare(strict_types=1);

namespace App\Message\Service\Exchange\Tasks\Beds24Send;

use App\Exchange\Service\Context\SyncContext;
use App\Exchange\Service\Contract\ExchangeHandlerInterface;
use App\Exchange\Service\Contract\ExchangeQueueProviderInterface;
use App\Exchange\Service\Contract\ExchangeTaskInterface;
use App\Exchange\Service\Mapping\MappingStrategyInterface;

final readonly class Beds24SendTask implements ExchangeTaskInterface
{
    public function __construct(
        private Beds24SendQueueProvider   $provider,
        private Beds24SendHandler         $handler,
        private Beds24SendMappingStrategy $strategy
    )
    {
    }

    public static function getTaskName(): string
    {
        return 'beds24_message_push';
    }

    public function getMaxBatchSize(): int
    {
        // Beds24 suele aceptar lotes de mensajes, pero mantenemos un número prudente
        return 20;
    }

    public function getSyncMode(): string
    {
        return SyncContext::MODE_PUSH;
    }

    public function getSyncProvider(): string
    {
        return 'beds24'; // Mismo proveedor que PMS
    }

    public function getQueueProvider(): ExchangeQueueProviderInterface
    {
        return $this->provider;
    }

    public function getHandler(): ExchangeHandlerInterface
    {
        return $this->handler;
    }

    public function getMappingStrategy(): MappingStrategyInterface
    {
        return $this->strategy;
    }

    /**
     * ✅ IMPLEMENTACIÓN DEL PROXY
     * El Handler llama a esto -> Tarea llama a Provider -> Provider llama a Repository
     */
    public function getGroupingMetadata(array $ids): array
    {
        return $this->provider->getGroupingMetadata($ids);
    }

}