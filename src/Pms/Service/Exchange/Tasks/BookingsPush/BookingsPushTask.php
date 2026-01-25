<?php
declare(strict_types=1);

namespace App\Pms\Service\Exchange\Tasks\BookingsPush;

use App\Exchange\Service\Context\SyncContext;
use App\Exchange\Service\Contract\ExchangeHandlerInterface;
use App\Exchange\Service\Contract\ExchangeQueueProviderInterface;
use App\Exchange\Service\Contract\ExchangeTaskInterface;
use App\Exchange\Service\Mapping\MappingStrategyInterface;

/**
 * Tarea de Envío de Reservas (Bookings Push).
 * Conecta:
 * - Provider: BookingsPushQueueProvider (Extrae colas pendientes)
 * - Strategy: BookingsPushMappingStrategy (Convierte a JSON Beds24 con reglas Legacy)
 * - Handler:  BookingsPushHandler (Procesa la respuesta éxito/error)
 */
final readonly class BookingsPushTask implements ExchangeTaskInterface
{
    public function __construct(
        private BookingsPushQueueProvider $provider,
        private BookingsPushHandler $handler,
        private BookingsPushMappingStrategy $strategy
    ) {}

    public static function getTaskName(): string
    {
        return 'bookings_push';
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
}