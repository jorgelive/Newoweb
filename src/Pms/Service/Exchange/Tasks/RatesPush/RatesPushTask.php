<?php
declare(strict_types=1);

namespace App\Pms\Service\Exchange\Tasks\RatesPush;

use App\Exchange\Service\Context\SyncContext;
use App\Exchange\Service\Contract\ExchangeHandlerInterface;
use App\Exchange\Service\Contract\ExchangeQueueProviderInterface;
use App\Exchange\Service\Contract\ExchangeTaskInterface;
use App\Exchange\Service\Mapping\MappingStrategyInterface;

/**
 * Tarea de Envío de Tarifas (Rates Push).
 * * En la nueva arquitectura, la Tarea es solo un contenedor de configuración (Glue Code).
 * Conecta:
 * 1. Provider: Quién da los datos (RatesPushQueueProvider).
 * 2. Strategy: Cómo se transforman (RatesNestedMappingStrategy).
 * 3. Handler: Qué hacer con el resultado (RatesPushHandler).
 */
final readonly class RatesPushTask implements ExchangeTaskInterface
{
    public function __construct(
        private RatesPushQueueProvider $provider,
        private RatesPushHandler $handler,
        private RatesNestedMappingStrategy $strategy
    ) {}

    public static function getTaskName(): string
    {
        return 'rates_push';
    }

    public function getMaxBatchSize(): int
    {
        return 50; // O 100, lo que soporte la API de Beds24 sin timeout.
    }
    // En tu clase Task (o la interfaz que use)
    public function getSyncMode(): string
    {
        // Retornamos la constante genérica del SyncContext
        return SyncContext::MODE_PUSH;
    }

    public function getSyncProvider(): string
    {
        // Identificamos el proveedor de forma limpia
        return 'beds24';
    }

    // --- COMPONENTES DEL MOTOR ---

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