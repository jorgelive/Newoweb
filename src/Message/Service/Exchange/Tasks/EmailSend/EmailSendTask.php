<?php

declare(strict_types=1);

namespace App\Message\Service\Exchange\Tasks\EmailSend;

use App\Exchange\Service\Context\SyncContext;
use App\Exchange\Service\Contract\ExchangeHandlerInterface;
use App\Exchange\Service\Contract\ExchangeQueueProviderInterface;
use App\Exchange\Service\Contract\ExchangeTaskInterface;
use App\Exchange\Service\Mapping\MappingStrategyInterface;

/**
 * El canal de correo dentro del motor de Exchange.
 *
 * Entra por aquí y no por una llamada suelta al mailer porque lo que hacía falta no era mandar
 * un correo —eso es una línea— sino **mandarlo con red**: cola persistente, reintentos con
 * espera, bloqueo por worker para que dos no manden lo mismo, y auditoría de qué se envió.
 * Todo eso ya está resuelto en el motor y funcionando para Beds24 y WhatsApp.
 */
final readonly class EmailSendTask implements ExchangeTaskInterface
{
    public function __construct(
        private EmailSendQueueProvider $provider,
        private EmailSendHandler $handler,
        private EmailSendMappingStrategy $strategy,
    ) {
    }

    public static function getTaskName(): string
    {
        return 'email_message_send';
    }

    /**
     * Lotes pequeños: cada correo es un envío independiente al transporte, así que el lote no
     * ahorra viajes — sólo sirve para no tener el worker reclamando de uno en uno.
     */
    public function getMaxBatchSize(): int
    {
        return 10;
    }

    public function getSyncMode(): string
    {
        return SyncContext::MODE_PUSH;
    }

    public function getSyncProvider(): string
    {
        return 'email';
    }

    public function getQueueProvider(): ExchangeQueueProviderInterface { return $this->provider; }
    public function getHandler(): ExchangeHandlerInterface { return $this->handler; }
    public function getMappingStrategy(): MappingStrategyInterface { return $this->strategy; }

    /**
     * @param list<string> $ids
     * @return array<string, mixed>
     */
    public function getGroupingMetadata(array $ids): array
    {
        return $this->provider->getGroupingMetadata($ids);
    }
}
