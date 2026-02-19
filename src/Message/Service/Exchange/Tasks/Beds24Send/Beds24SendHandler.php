<?php

declare(strict_types=1);

namespace App\Message\Service\Exchange\Tasks\Beds24Send;

use App\Exchange\Service\Contract\ExchangeHandlerInterface;
use App\Exchange\Service\Contract\ExchangeQueueItemInterface;
use App\Message\Entity\Beds24SendQueue;
use App\Message\Entity\Message;
use DateTimeImmutable;
use Throwable;

final class Beds24SendHandler implements ExchangeHandlerInterface
{
    public function handleSuccess(array $data, ExchangeQueueItemInterface $item): array
    {
        if (!$item instanceof Beds24SendQueue) {
            return ['status' => 'error', 'message' => 'Entidad incorrecta'];
        }

        // 1. Auditoría RAW (Ya se seteó en el BatchProcessor, pero aquí aseguramos lógica extra si hace falta)
        // El 'lastResponseRaw' ya viene lleno desde el Orchestrator/BatchProcessor.

        $item->setLastHttpCode(200);

        // 2. Actualizar Estado del Mensaje Padre
        // Si el mensaje se envió correctamente, actualizamos el Message principal
        $msg = $item->getMessage();
        if ($msg && $msg->getStatus() !== Message::STATUS_READ) {
            // Solo pasamos a SENT si no estaba ya en READ (por si acaso)
            $msg->setStatus(Message::STATUS_SENT);
        }

        // 3. Resultado de Ejecución
        $summary = [
            'status' => 'success',
            'remote_msg_id' => $data['id'] ?? null, // Si la API devuelve ID
            'timestamp' => (new DateTimeImmutable())->format('Y-m-d H:i:s')
        ];

        // 4. Marcar cola como Éxito
        $item->markSuccess(new DateTimeImmutable());

        return $summary;
    }

    public function handleFailure(Throwable $e, ExchangeQueueItemInterface $item): void
    {
        if (!$item instanceof Beds24SendQueue) {
            return;
        }

        $httpCode = (int) $e->getCode();
        $auditCode = $httpCode === 0 ? 500 : $httpCode;
        $msgError = sprintf('[Code %s] %s', $httpCode, $e->getMessage());

        $item->setLastHttpCode($auditCode);

        // Si falla el envío, marcamos el mensaje padre como FAILED también
        $msg = $item->getMessage();
        if ($msg) {
            $msg->setStatus(Message::STATUS_FAILED);
        }

        // Reintento: 2 minutos (mensajería requiere inmediatez o fallo rápido)
        $nextRetry = new DateTimeImmutable('+2 minutes');

        $item->markFailure($msgError, $auditCode, $nextRetry);
    }
}