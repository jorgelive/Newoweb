<?php

declare(strict_types=1);

namespace App\Pms\Service\Exchange\Tasks\BookingsPull;

use App\Exchange\Service\Contract\ExchangeHandlerInterface;
use App\Exchange\Service\Contract\ExchangeQueueItemInterface;
use App\Pms\Dto\Beds24BookingDto;
use App\Pms\Entity\PmsBookingsPullQueue;
use App\Pms\Service\Exchange\Persister\Beds24BookingPersister;
use DateTimeImmutable;
use Symfony\Component\Serializer\SerializerInterface;
use Throwable;

/**
 * Handler para la descarga de reservas (PULL).
 * ✅ Guarda auditoría completa RAW.
 * ✅ Procesa fila por fila (Soft Fail) para que una reserva corrupta no detenga el lote.
 * ✅ Genera estadísticas detalladas en 'execution_result'.
 */
final class BookingsPullHandler implements ExchangeHandlerInterface
{
    public function __construct(
        private readonly Beds24BookingPersister $persister,
        private readonly SerializerInterface $serializer // ✅ Inyección para mapeo automático
    ) {}

    public function handleSuccess(array $data, ExchangeQueueItemInterface $item): array
    {
        // 1. Validación de Tipo y Configuración
        if (!$item instanceof PmsBookingsPullQueue) {
            return ['status' => 'error', 'message' => 'Entidad no compatible (se esperaba PmsBookingsPullQueue)'];
        }

        $config = $item->getBeds24Config();
        if (!$config) {
            return ['status' => 'error', 'message' => 'El Job no tiene Configuración Beds24 asignada'];
        }

        // 2. AUDITORÍA: Guardar el JSON completo recibido
        try {
            $rawResponse = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $item->setLastResponseRaw($rawResponse);
        } catch (\JsonException $e) {
            $item->setLastResponseRaw('Error encoding JSON response: ' . $e->getMessage());
        }

        $item->setLastHttpCode(200);

        // 3. Preparación del Procesamiento
        // Asumimos que el Strategy ya extrajo el array de reservas en $data
        $bookings = $data;
        $total = count($bookings);
        $successCount = 0;
        $errors = [];

        // ✅ Limpieza de caché del Persister (Vital para procesos de larga duración)
        $this->persister->resetCache();

        // 4. Procesamiento Masivo (Fila por Fila)
        foreach ($bookings as $index => $bookingData) {
            try {
                // ✅ Deserialización Automática (Maneja tipos, fechas y nulos mejor que un array manual)
                /** @var Beds24BookingDto $dto */
                $dto = $this->serializer->denormalize($bookingData, Beds24BookingDto::class);

                // Persistencia (Upsert)
                $this->persister->upsert($config, $dto);

                $successCount++;

            } catch (Throwable $e) {
                // Captura de error individual (Soft Fail)
                $bookId = $bookingData['id'] ?? $bookingData['bookId'] ?? 'unknown';

                // Guardamos un mensaje corto para no saturar el JSON de log
                $errors[] = sprintf(
                    "Fila %d (ID %s): %s",
                    $index,
                    $bookId,
                    mb_substr($e->getMessage(), 0, 150)
                );
            }
        }

        // 5. Preparar Resultado de Ejecución (Estadísticas)
        $statusLog = 'success';
        if (count($errors) > 0) {
            $statusLog = ($successCount > 0) ? 'partial_success' : 'failed';
        }

        $resultStats = [
            'status'         => $statusLog,
            'window'         => $item->getArrivalFrom()?->format('Y-m-d'),
            'total_received' => $total,
            'processed'      => $successCount,
            'failed_count'   => count($errors),
            'errors'         => array_slice($errors, 0, 20), // Top 20 errores para diagnóstico rápido
            'processed_at'   => (new DateTimeImmutable())->format('Y-m-d H:i:s')
        ];

        // Guardar estadísticas en la columna JSON de la base de datos
        $item->setExecutionResult($resultStats);

        // 6. Transición de Estado
        $item->markSuccess(new DateTimeImmutable());

        // Nota: El Worker se encarga del $em->flush();
        return $resultStats;
    }

    public function handleFailure(Throwable $e, ExchangeQueueItemInterface $item): void
    {
        if (!$item instanceof PmsBookingsPullQueue) {
            return;
        }

        // 1. Obtener datos del error
        $httpCode = (int) $e->getCode();
        $auditCode = ($httpCode === 0) ? 500 : $httpCode;
        $errorMsg = sprintf('[Code %s] %s', $httpCode, $e->getMessage());

        // 2. Auditoría Técnica
        $item->setLastHttpCode($auditCode);

        // 3. Calcular Reintento (15 min para Pull, política estándar)
        $nextRetry = new DateTimeImmutable('+15 minutes');

        // 4. Transición de Estado
        $item->markFailure($errorMsg, $auditCode, $nextRetry);
    }
}