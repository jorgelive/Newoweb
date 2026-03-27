<?php

declare(strict_types=1);

namespace App\Message\Service\Exchange\Tasks\Beds24Receive;

use App\Exchange\Service\Contract\ExchangeHandlerInterface;
use App\Exchange\Service\Contract\ExchangeQueueItemInterface;
use App\Message\Dto\Beds24MessageDto;
use App\Message\Entity\Beds24ReceiveQueue;
use DateTimeImmutable;
use Throwable;

final readonly class Beds24ReceiveHandler implements ExchangeHandlerInterface
{
    public function __construct(
        private Beds24ReceivePersister $persister
    ) {}

    public function handleSuccess(array $data, ExchangeQueueItemInterface $item): array
    {
        if (!$item instanceof Beds24ReceiveQueue) {
            return ['status' => 'error', 'message' => 'Entidad incorrecta'];
        }

        try {
            $rawResponse = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $item->setLastResponseRaw($rawResponse);
        } catch (Throwable) {}

        $item->setLastHttpCode(200);

        try {
            // Transformar el array crudo a nuestro DTO fuertemente tipado
            $dtos = [];
            foreach ($data as $rawMsg) {
                $dtos[] = Beds24MessageDto::fromArray($rawMsg);
            }

            // Delegamos la lógica de negocio pesada y la idempotencia al Persister
            $stats = $this->persister->upsertMessages($item->getTargetBookId(), $dtos);

            $result = array_merge(['status' => 'success'], $stats);
            $item->setExecutionResult($result);
            $item->markSuccess(new DateTimeImmutable());

            return $result;

        } catch (Throwable $e) {
            // Si la reserva no existe u ocurre un error en BD
            $item->setExecutionResult(['status' => 'failed', 'error' => $e->getMessage()]);
            $item->markSuccess(new DateTimeImmutable());
            return ['status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    public function handleFailure(Throwable $e, ExchangeQueueItemInterface $item): void
    {
        if (!$item instanceof Beds24ReceiveQueue) {
            return;
        }

        $httpCode = $e->getCode() ?: 500;
        // Reintento estándar a los 5 minutos en caso de caída de red
        $item->markFailure($e->getMessage(), $httpCode, new DateTimeImmutable('+5 minutes'));
    }
}