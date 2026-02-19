<?php
declare(strict_types=1);

namespace App\Pms\Service\Exchange\Tasks\BookingsPull;

use App\Exchange\Service\Context\SyncContext;
use App\Exchange\Service\Contract\ExchangeHandlerInterface;
use App\Exchange\Service\Contract\ExchangeQueueProviderInterface;
use App\Exchange\Service\Contract\ExchangeTaskInterface;
use App\Exchange\Service\Mapping\MappingStrategyInterface;

/**
 * Tarea de Descarga de Reservas (Bookings Pull).
 * Conecta:
 * - Provider: BookingsPullQueueProvider (Extrae Jobs de descarga pendientes)
 * - Strategy: BookingsPullMappingStrategy (Genera la URL GET con filtros)
 * - Handler:  BookingsPullHandler (Recibe el JSON crudo y guarda reservas en BD)
 */
final readonly class BookingsPullTask implements ExchangeTaskInterface
{
    public function __construct(
        private BookingsPullQueueProvider $provider,
        private BookingsPullHandler $handler,
        private BookingsPullMappingStrategy $strategy
    ) {}

    public static function getTaskName(): string
    {
        return 'bookings_pull';
    }

    public function getMaxBatchSize(): int
    {
        return 1; // Pull es complejo (GET con filtros), solo admitimos 1 a la vez.
    }

    // En tu clase Task (o la interfaz que use)
    public function getSyncMode(): string
    {
        // Retornamos la constante genérica del SyncContext
        return SyncContext::MODE_PULL;
    }

    public function getSyncProvider(): string
    {
        // Identificamos el proveedor de forma limpia
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
     * ✅ IMPLEMENTACIÓN DEL PROXY
     * El Handler llama a esto -> Tarea llama a Provider -> Provider llama a Repository
     */
    public function getGroupingMetadata(array $ids): array
    {
        return $this->provider->getGroupingMetadata($ids);
    }
}