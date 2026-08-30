<?php
declare(strict_types=1);

namespace App\Exchange\Service\Contract;

use Throwable;

interface ExchangeHandlerInterface
{
    /**
     * Procesa los datos devueltos por la API.
     * DEBE retornar un array con el resumen de la operación.
     *
     * @param array<array-key, mixed> $data La respuesta del canal ya decodificada; la forma la
     *                                      pone el canal y la interpreta el handler.
     *
     * @return array<string, mixed> El resumen: al menos `status`, y lo que el handler añada.
     */
    public function handleSuccess(array $data, ExchangeQueueItemInterface $item): array;

    /**
     * Gestiona el fallo técnico o de negocio.
     */
    public function handleFailure(Throwable $e, ExchangeQueueItemInterface $item): void;
}