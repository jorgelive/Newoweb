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
 * ✅ Mantiene auditoría técnica completa (Request/Response RAW).
 * ✅ Sin simplificaciones: Lógica de negocio 100% funcional.
 */
final class RatesPushHandler implements ExchangeHandlerInterface
{
    /**
     * Procesa el éxito de la petición.
     * * @param array $data Respuesta decodificada de la API.
     * @param ExchangeQueueItemInterface $item La entidad de la cola.
     * @return array Resumen estructurado para persistir en la columna JSON 'execution_result'.
     */
    public function handleSuccess(array $data, ExchangeQueueItemInterface $item): array
    {
        // 1. Validación de seguridad de tipos
        if (!$item instanceof PmsRatesPushQueue) {
            return [
                'status' => 'error',
                'message' => 'Entidad no compatible: Se esperaba PmsRatesPushQueue'
            ];
        }

        // 2. AUDITORÍA: Guardar respuesta cruda completa (Vital para soporte técnico)
        try {
            $rawResponse = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $item->setLastResponseRaw($rawResponse);
        } catch (\JsonException $e) {
            $item->setLastResponseRaw('Error encoding JSON response: ' . $e->getMessage());
        }

        // Asumimos 200 ya que el transport llegó a handleSuccess
        $item->setLastHttpCode(200);

        // 3. Evaluación lógica de la API de Beds24
        // CALENDAR_POST puede devolver éxito general pero errores internos por ítem
        $success = $data['success'] ?? true;

        // 4. Transición de Estado (Cumpliendo firma estricta de la Interfaz)
        $item->markSuccess(new DateTimeImmutable());

        // 5. Preparar Resumen Detallado
        // Al estar aplanado, accedemos directamente a las propiedades de $item
        $executionSummary = [
            'status'       => $success ? 'success' : 'api_warning',
            'room_id'      => $item->getUnidadBeds24Map()?->getBeds24RoomId(),
            'start_date'   => $item->getFechaInicio()?->format('Y-m-d'),
            'end_date'     => $item->getFechaFin()?->format('Y-m-d'),
            'price'        => $item->getPrecio(),
            'effective_at' => $item->getEffectiveAt()?->format('Y-m-d H:i:s'),
            'api_msg'      => $data['message'] ?? 'Tarifa sincronizada correctamente'
        ];

        // Guardamos el resumen en la columna específica de la DB
        $item->setExecutionResult($executionSummary);

        return $executionSummary;
    }

    /**
     * Gestiona fallos técnicos de red, timeout o errores 500 de Beds24.
     */
    public function handleFailure(Throwable $e, ExchangeQueueItemInterface $item): void
    {
        if (!$item instanceof PmsRatesPushQueue) {
            return;
        }

        // 1. Obtener código HTTP o fallback
        $httpCode = (int) $e->getCode();
        $auditCode = ($httpCode === 0) ? 500 : $httpCode;

        $errorMsg = sprintf('[Code %s] %s', $httpCode, $e->getMessage());

        // 2. AUDITORÍA TÉCNICA
        $item->setLastHttpCode($auditCode);

        // 3. Política de Reintento
        // Para tarifas se sugiere un reintento corto (5 minutos)
        $nextRetry = new DateTimeImmutable('+5 minutes');

        // 4. Transición de Estado (Firma Estricta: Razón, Código, Reintento)
        $item->markFailure($errorMsg, $auditCode, $nextRetry);
    }
}