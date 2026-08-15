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
        /*
         * Reservas por petición. Beds24 acepta `bookingId` repetido y devuelve todo mezclado;
         * Beds24InvoiceReceiveMappingStrategy lo reparte por el `bookingId` de cada línea.
         *
         * Estuvo en 1 por miedo a los cortes de paginación, pero eso lo resuelve
         * Beds24ExchangeClient, que recorre `pages.nextPageLink` y fusiona los `data` antes
         * de llegar aquí. El límite real es OTRO: el lote entero comparte una transacción y
         * un lock de 60 s (ver el TTL de Beds24InvoiceReceiveQueueProvider). Si se sube
         * mucho, una respuesta con muchas páginas puede pasarse del TTL y el watchdog de la
         * siguiente ejecución marcaría todo el lote como `failed`.
         *
         * 10 deja margen de sobra y ya multiplica por diez el drenaje de la cola.
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

    /**
     * @param list<string> $ids
     * @return array<string, mixed> Metadatos de agrupación por lote.
     */
    public function getGroupingMetadata(array $ids): array
    {
        return $this->provider->getGroupingMetadata($ids);
    }
}
