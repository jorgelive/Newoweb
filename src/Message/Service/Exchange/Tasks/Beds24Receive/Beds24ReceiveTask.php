<?php

declare(strict_types=1);

namespace App\Message\Service\Exchange\Tasks\Beds24Receive;

use App\Exchange\Service\Context\SyncContext;
use App\Exchange\Service\Contract\ExchangeHandlerInterface;
use App\Exchange\Service\Contract\ExchangeQueueProviderInterface;
use App\Exchange\Service\Contract\ExchangeTaskInterface;
use App\Exchange\Service\Mapping\MappingStrategyInterface;

final readonly class Beds24ReceiveTask implements ExchangeTaskInterface
{
    public function __construct(
        private Beds24ReceiveQueueProvider $provider,
        private Beds24ReceiveHandler $handler,
        private Beds24ReceiveMappingStrategy $strategy
    ) {}

    public static function getTaskName(): string
    {
        return 'beds24_message_pull';
    }

    public function getMaxBatchSize(): int
    {
        return 10; // Extrae lotes pequeños pero paralelizables para no sobrecargar
    }

    public function getSyncMode(): string
    {
        return SyncContext::MODE_PULL;
    }

    public function getSyncProvider(): string
    {
        return 'beds24';
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

    public function getGroupingMetadata(array $ids): array
    {
        return $this->provider->getGroupingMetadata($ids);
    }
}