<?php
declare(strict_types=1);

namespace App\Exchange\Service\Contract;

use Throwable;

interface ExchangeHandlerInterface
{
    /**
     * Procesa los datos devueltos por la API.
     * DEBE retornar un array con el resumen de la operación.
     */
    public function handleSuccess(array $data, ExchangeQueueItemInterface $item): array;

    /**
     * Gestiona el fallo técnico o de negocio.
     */
    public function handleFailure(Throwable $e, ExchangeQueueItemInterface $item): void;
}