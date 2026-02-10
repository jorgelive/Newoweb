<?php

declare(strict_types=1);

namespace App\Pms\Service\Exchange\Tasks\BookingsPush;

use App\Exchange\Service\Contract\ExchangeHandlerInterface;
use App\Exchange\Service\Contract\ExchangeQueueItemInterface;
use App\Pms\Entity\PmsBookingsPushQueue;
use DateTimeImmutable;
use Throwable;

/**
 * Handler para la subida de reservas (PUSH).
 * ✅ Guarda auditoría completa (Response RAW, HTTP Code).
 * ✅ Gestiona Snapshots de IDs.
 * ✅ Compatible con ExchangeQueueItemInterface.
 */
final class BookingsPushHandler implements ExchangeHandlerInterface
{
    public function handleSuccess(array $data, ExchangeQueueItemInterface $item): array
    {
        // 1. Validación de Tipo
        if (!$item instanceof PmsBookingsPushQueue) {
            return ['status' => 'error', 'message' => 'Entidad no compatible'];
        }

        // 2. AUDITORÍA: Guardar respuesta cruda completa (Sin simplificar)
        // Esto es vital para depurar qué respondió exactamente Beds24
        try {
            $rawResponse = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $item->setLastResponseRaw($rawResponse);
        } catch (\JsonException $e) {
            $item->setLastResponseRaw('Error encoding JSON: ' . $e->getMessage());
        }

        $item->setLastHttpCode(200); // Asumimos 200 si llegó a handleSuccess

        // 3. Extracción de Datos de Negocio
        $link = $item->getLink();
        $remoteId =  $data['new']['id'] ?? $data['id'] ?? $data['new'][0]['id'] ?? null;
        $success = $data['success'] ?? true;

        if (!$success) {
            throw new \RuntimeException($data['message'] ?? 'Error lógico en API Beds24 (Success=false)');
        }

        // 4. Lógica de Actualización y Snapshots
        if ($link) {
            // Snapshot del UUID interno
            $item->setLinkIdOriginal((string) $link->getId());

            if ($remoteId) {
                $strRemoteId = (string) $remoteId;

                // Actualizar Fuente de Verdad (Link)
                if ($link->getBeds24BookId() !== $strRemoteId) {
                    $link->setBeds24BookId($strRemoteId);
                }
                $link->setLastSeenAt(new DateTimeImmutable());

                // Actualizar Snapshot Externo
                $item->setBeds24BookIdOriginal($strRemoteId);
            }
        } elseif ($remoteId) {
            // Caso: Link borrado físicamente
            $item->setBeds24BookIdOriginal((string) $remoteId);
        }

        // 5. Preparar Resultado de Ejecución
        $logLinkId = $item->getLinkIdOriginal() ?? ($link ? (string)$link->getId() : 'UNKNOWN');
        $resultData = [
            'status'    => 'success',
            'remote_id' => $remoteId,
            'link_id'   => $logLinkId,
            'msg'       => $data['message'] ?? 'Procesado correctamente'
        ];

        // Guardar el resultado estructurado en la BD
        $item->setExecutionResult($resultData);

        // 6. Transición de Estado (Firma Estricta)
        $item->markSuccess(new DateTimeImmutable());

        return $resultData;
    }

    public function handleFailure(Throwable $e, ExchangeQueueItemInterface $item): void
    {
        if (!$item instanceof PmsBookingsPushQueue) {
            return;
        }

        // 1. Obtener datos del error
        $httpCode = (int) $e->getCode();
        // Si el código es 0 (excepción interna PHP), usamos 500 para registro
        $auditCode = $httpCode === 0 ? 500 : $httpCode;

        $errorMsg = sprintf('[Code %s] %s', $httpCode, $e->getMessage());

        // 2. AUDITORÍA: Guardar el código de error en la columna específica
        $item->setLastHttpCode($auditCode);

        // (Opcional) Podrías guardar el stack trace en lastResponseRaw si quieres auditoría extrema
        // $item->setLastResponseRaw($e->getTraceAsString());

        // 3. Calcular Reintento (5 minutos)
        $delayMinutes = 5;
        $nextRetry = new DateTimeImmutable(sprintf('+%d minutes', $delayMinutes));

        // 4. Transición de Estado (Firma Estricta: Razón, Código, Reintento)
        $item->markFailure($errorMsg, $auditCode, $nextRetry);
    }
}