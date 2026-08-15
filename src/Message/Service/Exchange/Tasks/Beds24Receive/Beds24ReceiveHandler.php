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

    /**
     * @param array<string, mixed> $data Respuesta cruda del canal.
     * @return array<string, mixed>
     */
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
            // 🔥 FALLO ES FALLO. Antes esto llamaba a `markSuccess()` con un `status: failed`
            // dentro del JSON, y el resultado era que un mensaje del huésped se PERDÍA para
            // siempre sin que nadie se enterase: sin `Message`, sin conversación, sin no
            // leídos. Sólo lo veía quien abriera el CRUD de la cola a leer el resultado.
            //
            // Y el caso que lo dispara es de lo más normal: `upsertMessages()` lanza si la
            // reserva todavía no está sincronizada, y el mensaje del huésped puede llegar
            // ANTES que el webhook de su reserva. Es una carrera, no una anomalía.
            //
            // Marcándolo como fallo se reintenta a los 5 minutos —igual que `handleFailure()`
            // con una caída de red— y esa carrera se cura sola en el siguiente intento.
            $item->setExecutionResult(['status' => 'failed', 'error' => $e->getMessage()]);
            $item->markFailure($e->getMessage(), $e->getCode() ?: 500, new DateTimeImmutable('+5 minutes'));

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