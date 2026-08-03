<?php

declare(strict_types=1);

namespace App\Pms\Service\Exchange\Tasks\InvoiceReceive;

use App\Exchange\Service\Context\SyncContext;
use App\Exchange\Service\Contract\ExchangeHandlerInterface;
use App\Exchange\Service\Contract\ExchangeQueueProviderInterface;
use App\Exchange\Service\Contract\ExchangeTaskInterface;
use App\Exchange\Service\Mapping\MappingStrategyInterface;

final readonly class Beds24InvoiceReceiveTask implements ExchangeTaskInterface
{
    public function __construct(
        private Beds24InvoiceReceiveQueueProvider $provider,
        private Beds24InvoiceReceiveHandler $handler,
        private Beds24InvoiceReceiveMappingStrategy $strategy
    ) {}

    public static function getTaskName(): string
    {
        return 'beds24_invoice_receive';
    }

    public function getMaxBatchSize(): int
    {
        // Lote de 1 para evitar cortes por paginación en Beds24 (igual que mensajes).
        return 1;
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
