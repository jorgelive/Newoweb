<?php

declare(strict_types=1);

namespace App\Message\Service\Exchange\Tasks\Beds24Send;

use App\Exchange\Service\Contract\ExchangeHandlerInterface;
use App\Exchange\Service\Contract\ExchangeQueueItemInterface;
use App\Message\Entity\Beds24SendQueue;
use App\Message\Entity\Message;
use App\Message\Service\MercureBroadcaster;
use DateTimeImmutable;
use Throwable;

final class Beds24SendHandler implements ExchangeHandlerInterface
{
    public function __construct(
        private readonly MercureBroadcaster $mercureBroadcaster
    ) {}

    public function handleSuccess(array $data, ExchangeQueueItemInterface $item): array
    {
        if (!$item instanceof Beds24SendQueue) {
            return ['status' => 'error', 'message' => 'Entidad incorrecta'];
        }

        // 1. Auditoría RAW (El 'lastResponseRaw' ya se llenó en el BatchProcessor)
        $item->setLastHttpCode(200);

        // 2. Extraer el ID externo del payload de Beds24
        // Según la API v2 de Beds24, al crear un mensaje viene en ['new']['id']
        // Dejamos el fallback ['id'] por si en algún momento hacen un update o cambia la respuesta.
        $remoteId = $data['new']['id'] ?? $data['id'] ?? null;

        // 3. Actualizar Estado del Mensaje Padre y Guardar ID de Idempotencia
        $msg = $item->getMessage();

        if ($msg) {
            // Pasamos a SENT si no estaba ya en READ
            if ($msg->getStatus() !== Message::STATUS_READ) {
                $msg->setStatus(Message::STATUS_SENT);
            }

            // 🔥 CRÍTICO: Guardamos el ID remoto en el mensaje para evitar
            // que el proceso de PULL/Webhooks lo vuelva a insertar como duplicado.
            if ($remoteId) {
                $msg->setBeds24ExternalId((string) $remoteId);
            }

            // 🔥 Avisamos a Vue que se envió
            $this->mercureBroadcaster->broadcastMessage($msg);
        }

        // 4. Construir el Resultado de Ejecución para la auditoría JSON de la cola
        $summary = [
            'status' => 'success',
            'remote_msg_id' => $remoteId,
            'timestamp' => (new DateTimeImmutable())->format('Y-m-d H:i:s')
        ];

        // 5. Marcar la cola como Éxito (limpia bloqueos y quita de la lista de pendientes)
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
            // 🔥 Avisamos a Vue que falló el envío
            $this->mercureBroadcaster->broadcastMessage($msg);
        }

        // Reintento: 2 minutos (mensajería requiere inmediatez o fallo rápido)
        $nextRetry = new DateTimeImmutable('+2 minutes');

        $item->markFailure($msgError, $auditCode, $nextRetry);
    }
}