<?php

declare(strict_types=1);

namespace App\Pms\Service\Exchange\Tasks\InvoiceReceive;

use App\Exchange\Service\Contract\ExchangeHandlerInterface;
use App\Exchange\Service\Contract\ExchangeQueueItemInterface;
use App\Pms\Dto\Beds24InvoiceItemDto;
use App\Pms\Entity\Beds24InvoiceReceiveQueue;
use DateTimeImmutable;
use Throwable;

final readonly class Beds24InvoiceReceiveHandler implements ExchangeHandlerInterface
{
    public function __construct(
        private Beds24InvoiceReceivePersister $persister
    ) {}

    /**
     * @param array<string, mixed> $data Respuesta cruda del canal.
     * @return array<string, mixed>
     */
    public function handleSuccess(array $data, ExchangeQueueItemInterface $item): array
    {
        if (!$item instanceof Beds24InvoiceReceiveQueue) {
            return ['status' => 'error', 'message' => 'Entidad incorrecta'];
        }

        try {
            $rawResponse = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $item->setLastResponseRaw($rawResponse);
        } catch (Throwable) {}

        $item->setLastHttpCode(200);

        try {
            // $data ya viene aplanado por el MappingStrategy (lista de invoiceItems).
            $dtos = [];
            foreach ($data as $rawItem) {
                $dtos[] = Beds24InvoiceItemDto::fromArray($rawItem);
            }

            $stats = $this->persister->upsertCargos($item->getTargetBookId(), $dtos);

            $result = array_merge(['status' => 'success'], $stats);
            $item->setExecutionResult($result);
            $item->markSuccess(new DateTimeImmutable());

            return $result;

        } catch (Throwable $e) {
            // Si la reserva no existe u ocurre un error en BD: no reintentamos en bucle,
            // igual que mensajes (markSuccess con status 'failed' en el resultado).
            $item->setExecutionResult(['status' => 'failed', 'error' => $e->getMessage()]);
            $item->markSuccess(new DateTimeImmutable());
            return ['status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    public function handleFailure(Throwable $e, ExchangeQueueItemInterface $item): void
    {
        if (!$item instanceof Beds24InvoiceReceiveQueue) {
            return;
        }

        $httpCode = $e->getCode() ?: 500;
        // Reintento estándar a los 5 minutos en caso de caída de red.
        $item->markFailure($e->getMessage(), $httpCode, new DateTimeImmutable('+5 minutes'));
    }
}
