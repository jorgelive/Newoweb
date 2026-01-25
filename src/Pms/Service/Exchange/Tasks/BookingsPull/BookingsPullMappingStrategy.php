<?php
declare(strict_types=1);

namespace App\Pms\Service\Exchange\Tasks\BookingsPull;

use App\Exchange\Service\Common\HomogeneousBatch;
use App\Exchange\Service\Mapping\ItemResult;
use App\Exchange\Service\Mapping\MappingResult;
use App\Exchange\Service\Mapping\MappingStrategyInterface;
use App\Pms\Entity\PmsBookingsPullQueue;
use App\Pms\Entity\PmsUnidad;

/**
 * Estrategia de Descarga (Pull):
 * Convierte un JOB de base de datos en una petición GET con filtros.
 */
final readonly class BookingsPullMappingStrategy implements MappingStrategyInterface
{
    public function map(HomogeneousBatch $batch): MappingResult
    {
        // 1. Contexto Homogéneo
        $config = $batch->getConfig();
        $endpoint = $batch->getEndpoint();

        // 2. Construcción de URL Completa (Base URL del Config + Endpoint relativo)
        $fullUrl = rtrim($config->getBaseUrl(), '/') . '/' . ltrim((string)$endpoint->getEndpoint(), '/');

        // 3. Obtenemos el Job (En Pull el batch suele ser de 1 elemento)
        /** @var PmsBookingsPullQueue $job */
        $job = $batch->getItems()[0];

        // 4. Construcción del Payload (Query Params para GET)
        $payload = [
            'arrivalFrom'      => $job->getArrivalFrom()?->format('Y-m-d'),
            'arrivalTo'        => $job->getArrivalTo()?->format('Y-m-d'),
            'includeInvoice'   => true,
            'includeInfoItems' => true,
            // 'modifiedSince' => ... (opcional si implementas lógica incremental)
        ];

        // 5. Filtrado de Habitaciones (Opcional)
        // Solo pedimos las habitaciones que pertenecen a esta cuenta de Beds24
        $roomIds = [];
        foreach ($job->getUnidades() as $unidad) {
            /** @var PmsUnidad $unidad */
            foreach ($unidad->getBeds24Maps() as $map) {
                // Validación estricta: Solo mapas que coincidan con la config del lote actual
                if ($map->getBeds24Config() === $config) {
                    $roomIds[] = (int)$map->getBeds24RoomId();
                }
            }
        }

        if (!empty($roomIds)) {
            $payload['roomId'] = array_values(array_unique($roomIds));
        }

        // 6. Retorno del Resultado Mapeado
        return new MappingResult(
            method: (string)$endpoint->getMetodo(), // Casting seguro
            fullUrl: $fullUrl,                      // URL absoluta calculada
            payload: $payload,
            config: $config,
            correlationMap: ['job' => $job->getId()] // Mapa simple para correlacionar la vuelta
        );
    }

    public function parseResponse(array $apiResponse, MappingResult $mapping): array
    {
        $jobId = $mapping->correlationMap['job'];

        // Validación extra: A veces las APIs devuelven 200 OK con un JSON de error
        if (isset($apiResponse['success']) && $apiResponse['success'] === false) {
            return [
                $jobId => new ItemResult(
                    $jobId,
                    false,
                    $apiResponse['message'] ?? 'Error lógico en API al descargar'
                )
            ];
        }

        $count = count($apiResponse);

        // Éxito: Pasamos los datos crudos en 'extraData' para que el Handler los procese
        return [
            $jobId => new ItemResult(
                queueItemId: $jobId,
                success: true,
                message: "Descarga OK: $count reservas obtenidas",
                remoteId: null,
                extraData: $apiResponse['data'] ?? []
            )
        ];
    }
}