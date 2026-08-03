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
        return 'beds24_message_receive';
    }

    public function getMaxBatchSize(): int
    {
        /*
         * Reservas por petición. Beds24 acepta `bookingId` repetido y devuelve los mensajes
         * de todas mezclados; Beds24ReceiveMappingStrategy los reparte por su `bookingId`.
         *
         * Estuvo en 1 por los cortes de paginación, pero eso lo resuelve Beds24ExchangeClient
         * recorriendo `pages.nextPageLink` y fusionando los `data`. El límite real es el lock
         * de 60 s que comparte el lote (TTL de Beds24ReceiveQueueProvider): un historial largo
         * son varias páginas, y pasarse del TTL haría que el watchdog marcara todo `failed`.
         *
         * Los mensajes pesan más que las facturas (86 en tres reservas es normal), así que se
         * queda en 10 y no más.
         */
        return 10;
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