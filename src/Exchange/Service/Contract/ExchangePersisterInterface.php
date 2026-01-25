<?php
declare(strict_types=1);

namespace App\Exchange\Service\Contract;

/**
 * Define el contrato para persistir datos de negocio tras un intercambio exitoso.
 */
interface ExchangePersisterInterface
{
    /**
     * @param array<string, mixed> $response
     * @param ExchangeQueueItemInterface $item
     */
    public function persist(array $response, ExchangeQueueItemInterface $item): void;
}