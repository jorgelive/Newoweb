<?php
declare(strict_types=1);

namespace App\Pms\Service\Exchange\Tasks\BookingsPull;

use App\Exchange\Service\Contract\ExchangeHandlerInterface;
use App\Exchange\Service\Contract\ExchangeQueueItemInterface;
use App\Pms\Dto\Beds24BookingDto;
use App\Pms\Entity\PmsBookingsPullQueue;
use App\Pms\Service\Exchange\Persister\Beds24BookingPersister;
use DateTimeImmutable;
use Throwable;

/**
 * Handler robusto que orquesta la respuesta del API.
 * Aisla errores individuales por fila y reporta resultados al motor.
 */
final class BookingsPullHandler implements ExchangeHandlerInterface
{
    public function __construct(
        private readonly Beds24BookingPersister $persister
    ) {}

    /**
     * Procesa los datos de Beds24 y devuelve un resumen para la base de datos.
     */
    public function handleSuccess(array $data, ExchangeQueueItemInterface $item): array
    {
        if (!$item instanceof PmsBookingsPullQueue || !$item->getBeds24Config()) {
            return ['status' => 'error', 'message' => 'Entidad o Configuración inválida'];
        }

        $config = $item->getBeds24Config();

        /**
         * ✅ LIMPIEZA TOTAL:
         * Si el Strategy hizo bien su trabajo, $data YA ES el array de reservas puras.
         */
        $bookings = $data;

        $total = count($bookings);
        $successCount = 0;
        $errors = [];

        foreach ($bookings as $index => $bookingData) {
            try {
                // Aquí ya no entrarán 'success', 'type' o 'count' porque no están en la lista
                $dto = Beds24BookingDto::fromArray($bookingData);
                $this->persister->upsert($config, $dto);
                $successCount++;
            } catch (Throwable $e) {
                $bookId = $bookingData['id'] ?? 'unknown';
                $errors[] = "Fila $index (ID $bookId): " . $e->getMessage();
            }
        }

        $item->markSuccess(new DateTimeImmutable());

        return [
            'status' => count($errors) === 0 ? 'success' : ($successCount > 0 ? 'partial_success' : 'failed'),
            'total_received' => $total,
            'processed' => $successCount,
            'failed' => count($errors),
            'errors' => array_slice($errors, 0, 10),
            'processed_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s')
        ];
    }

    /**
     * Gestiona errores de red o excepciones fatales.
     */
    public function handleFailure(Throwable $e, ExchangeQueueItemInterface $item): void
    {
        // Reintento en 10 minutos
        $item->markFailure(
            mb_substr($e->getMessage(), 0, 255),
            (int) $e->getCode() ?: 500,
            new DateTimeImmutable('+10 minutes')
        );
    }
}