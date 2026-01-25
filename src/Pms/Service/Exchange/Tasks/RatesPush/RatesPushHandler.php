<?php
declare(strict_types=1);

namespace App\Pms\Service\Exchange\Tasks\RatesPush;

use App\Exchange\Service\Contract\ExchangeHandlerInterface;
use App\Exchange\Service\Contract\ExchangeQueueItemInterface;
use App\Pms\Entity\PmsRatesPushQueue;
use DateTimeImmutable;
use Throwable;

/**
 * Gestiona el resultado del envío de tarifas a Beds24.
 * Adaptado para la estructura de cola aplanada (PmsRatesPushQueue).
 */
final class RatesPushHandler implements ExchangeHandlerInterface
{
    /**
     * Procesa el éxito de la petición.
     * @return array Resumen de ejecución para 'execution_result'.
     */
    public function handleSuccess(array $data, ExchangeQueueItemInterface $item): array
    {
        // Validación actualizada a la entidad aplanada
        if (!$item instanceof PmsRatesPushQueue) {
            return ['status' => 'error', 'message' => 'Entidad no compatible: Se esperaba PmsRatesPushQueue'];
        }

        // Beds24 CALENDAR_POST suele devolver éxito a nivel de raíz o por ítem
        $success = $data['success'] ?? true;

        // 1. Marcado de éxito en la cola (método de la interfaz)
        $item->markSuccess(new DateTimeImmutable());

        // 2. Construcción del resumen (vía JSON en DB)
        // ACCESO DIRECTO: Ya no navegamos a ->getQueue(), los datos están aquí.
        return [
            'status'       => $success ? 'success' : 'api_warning',
            'effective_at' => $item->getEffectiveAt()?->format('Y-m-d'),
            'price'        => $item->getPrecio(),
            'start_date'   => $item->getFechaInicio()?->format('Y-m-d'),
            'room_id'      => $item->getUnidadBeds24Map()?->getBeds24RoomId(),
            'api_response' => $data['message'] ?? 'OK'
        ];
    }

    /**
     * Gestiona fallos técnicos de red o de API.
     */
    public function handleFailure(Throwable $e, ExchangeQueueItemInterface $item): void
    {
        // Reintento en 5 minutos para tarifas
        $nextRetry = new DateTimeImmutable('+5 minutes');

        $item->markFailure(
            reason: mb_substr($e->getMessage(), 0, 255),
            httpCode: (int) $e->getCode() ?: 500,
            nextRetry: $nextRetry
        );
    }
}