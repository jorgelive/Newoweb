<?php
declare(strict_types=1);

namespace App\Exchange\Service\Common;

use App\Exchange\Service\Contract\ChannelConfigInterface;
use App\Exchange\Service\Contract\EndpointInterface;
use App\Exchange\Service\Contract\ExchangeQueueItemInterface;
use InvalidArgumentException;

/**
 * Representa un lote de trabajo garantizado de ser UNIFORME.
 * Todos los ítems comparten exáctamente la misma Configuración y el mismo Endpoint.
 */
final readonly class HomogeneousBatch
{
    public function __construct(
        private ChannelConfigInterface $config,
        private EndpointInterface $endpoint,
        /** @var ExchangeQueueItemInterface[] $items */
        private array $items
    ) {
        if (empty($items)) {
            throw new InvalidArgumentException("Logic Error: No se puede crear un HomogeneousBatch vacío.");
        }
    }

    public function getConfig(): ChannelConfigInterface
    {
        return $this->config;
    }

    public function getEndpoint(): EndpointInterface
    {
        return $this->endpoint;
    }

    /**
     * @return ExchangeQueueItemInterface[]
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }
}