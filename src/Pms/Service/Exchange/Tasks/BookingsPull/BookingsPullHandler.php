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
        private readonly SerializerInterface $serializer
    ) {}

    public function handleSuccess(array $data, ExchangeQueueItemInterface $item): array
    {
        // 1. Validación de Tipo y Configuración
        if (!$item instanceof PmsBookingsPullQueue) {
            return ['status' => 'error', 'message' => 'Entidad no compatible (se esperaba PmsBookingsPullQueue)'];
        }

        $config = $item->getConfig();
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
        $bookings = $data;
        $total = count($bookings);

        $stats = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed'  => 0
        ];

        $messages = []; // Log detallado (limitado)

        // ✅ Limpieza de caché del Persister (Obs #7)
        $this->persister->resetCache();

        // 4. Procesamiento Masivo (Fila por Fila)
        foreach ($bookings as $index => $bookingData) {
            $bookId = $bookingData['id'] ?? $bookingData['bookId'] ?? 'unknown';

            try {
                // ✅ Deserialización Automática
                /** @var Beds24BookingDto $dto */
                $dto = $this->serializer->denormalize($bookingData, Beds24BookingDto::class);

                // Persistencia (Upsert) - Ahora retorna array con info
                $result = $this->persister->upsert($config, $dto);

                // Contabilizar según resultado
                if ($result['status'] === 'skipped') {
                    $stats['skipped']++;
                    // Solo guardamos mensaje de skip si es relevante para debug (opcional)
                    // $messages[] = "SKIP ID $bookId: {$result['message']}";
                } else {
                    if ($result['action'] === 'created') $stats['created']++;
                    else $stats['updated']++;
                }

            } catch (Throwable $e) {
                // Captura de error individual (Soft Fail)
                $stats['failed']++;

                // Guardamos el error para diagnóstico
                $messages[] = sprintf(
                    "ERROR Fila %d (ID %s): %s",
                    $index,
                    $bookId,
                    mb_substr($e->getMessage(), 0, 200) // Truncar mensajes largos
                );
            }
        }

        // 5. Preparar Resultado de Ejecución (Estadísticas)
        $processedTotal = $stats['created'] + $stats['updated'];

        // Estado lógico del Job:
        // - 'success' si todo OK (incluso si hubo skips legítimos).
        // - 'partial_success' si hubo fallos (excepciones).
        // - 'failed' si falló todo lo que se intentó.
        $statusLog = 'success';
        if ($stats['failed'] > 0) {
            $statusLog = ($processedTotal > 0 || $stats['skipped'] > 0) ? 'partial_success' : 'failed';
        }

        $resultStats = [
            'status'         => $statusLog,
            'window'         => $item->getArrivalFrom()?->format('Y-m-d'),
            'total_received' => $total,
            'stats'          => $stats, // Desglose: created, updated, skipped, failed
            'errors'         => array_slice($messages, 0, 50), // Guardar hasta 50 mensajes de error
            'processed_at'   => (new DateTimeImmutable())->format('Y-m-d H:i:s')
        ];

        // Guardar estadísticas en la columna JSON de la base de datos
        $item->setExecutionResult($resultStats);

        // 6. Transición de Estado
        $item->markSuccess(new DateTimeImmutable());

        return $resultStats;
    }

    public function handleFailure(Throwable $e, ExchangeQueueItemInterface $item): void
    {
        if (!$item instanceof PmsBookingsPullQueue) {
            return;
        }

        $httpCode = (int) $e->getCode();
        $auditCode = ($httpCode === 0) ? 500 : $httpCode;
        $errorMsg = sprintf('[Code %s] %s', $httpCode, $e->getMessage());

        $item->setLastHttpCode($auditCode);

        // Política de reintento: 15 minutos
        $nextRetry = new DateTimeImmutable('+15 minutes');

        $item->markFailure($errorMsg, $auditCode, $nextRetry);
    }
}