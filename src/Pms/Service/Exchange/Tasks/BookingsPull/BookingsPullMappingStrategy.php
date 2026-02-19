<?php

declare(strict_types=1);

namespace App\Pms\Service\Exchange\Tasks\BookingsPull;

use App\Exchange\Service\Common\HomogeneousBatch;
use App\Exchange\Service\Mapping\ItemResult;
use App\Exchange\Service\Mapping\MappingResult;
use App\Exchange\Service\Mapping\MappingStrategyInterface;
use App\Pms\Entity\PmsBookingsPullQueue;
use App\Pms\Entity\PmsUnidad;
use DateTimeImmutable;

/**
 * Estrategia de Mapeo para Descarga de Reservas (PULL).
 * * Convierte un Job de PmsBookingsPullQueue en una petición GET filtrada a Beds24.
 * * Maneja la respuesta masiva para pasarla al Handler.
 */
final readonly class BookingsPullMappingStrategy implements MappingStrategyInterface
{
    /**
     * Construye la petición GET.
     */
    public function map(HomogeneousBatch $batch): MappingResult
    {
        $config = $batch->getConfig();
        $endpoint = $batch->getEndpoint();

        // 1. Construcción de URL Base
        $fullUrl = rtrim($config->getBaseUrl(), '/') . '/' . ltrim((string)$endpoint->getEndpoint(), '/');

        // 2. Extracción del Job (Pull siempre es 1 ítem por lote lógico)
        /** @var PmsBookingsPullQueue $job */
        $job = $batch->getItems()[0];

        // 3. Definición de Fechas (Con Fallback de seguridad)
        $arrivalFrom = $job->getArrivalFrom() ?? new DateTimeImmutable('today');
        $arrivalTo = $job->getArrivalTo(); // Puede ser null (open-ended)

        // 4. Construcción de Parámetros (Query String)
        $payload = [
            'arrivalFrom'      => $arrivalFrom->format('Y-m-d'),
            'includeInvoice'   => true,
            'includeInfoItems' => true,
            // ✅ ESTADOS OBLIGATORIOS:
            // Si no enviamos esto, Beds24 oculta 'cancelled' por defecto.
            // Al listarlos explícitamente, forzamos la descarga del historial completo.
            'status'           => [
                'confirmed',
                'new',
                'request',
                'cancelled', // Vital para detectar cancelaciones
                'black'      // Vital para detectar bloqueos manuales o mantenimientos
            ],
        ];

        if ($arrivalTo) {
            $payload['arrivalTo'] = $arrivalTo->format('Y-m-d');
        }

        // 5. Filtrado de Habitaciones (Scope Isolation)
        // Solo solicitamos las habitaciones vinculadas a *esta* configuración específica.
        $roomIds = [];
        foreach ($job->getUnidades() as $unidad) {
            /** @var PmsUnidad $unidad */
            foreach ($unidad->getBeds24Maps() as $map) {
                // Validación estricta: El mapa debe pertenecer a la cuenta que estamos consultando
                if ($map->getConfig()->getId() === $config->getId()) {
                    $roomIds[] = (int)$map->getBeds24RoomId();
                }
            }
        }

        // Si hay habitaciones específicas, filtramos. Si no, Beds24 devuelve todo (según permisos del token).
        if (!empty($roomIds)) {
            $payload['roomId'] = array_values(array_unique($roomIds));
        }

        return new MappingResult(
            method: (string)$endpoint->getMetodo(),
            fullUrl: $fullUrl,
            payload: $payload,
            config: $config,
            correlationMap: ['job' => (string)$job->getId()]
        );
    }

    /**
     * Procesa la respuesta masiva.
     * Beds24 v2 GET bookings suele devolver un array de objetos o un wrapper con error.
     */
    public function parseResponse(array $apiResponse, MappingResult $mapping): array
    {
        $jobId = $mapping->correlationMap['job'];

        // 1. Detección de Errores Lógicos (API responde 200 pero con success: false)
        if (isset($apiResponse['success']) && $apiResponse['success'] === false) {
            $msg = $apiResponse['message'] ?? 'Error lógico en API al descargar reservas';

            // Si hay errores detallados, tomamos el primero
            if (isset($apiResponse['errors'][0]['message'])) {
                $msg .= ': ' . $apiResponse['errors'][0]['message'];
            }

            return [
                $jobId => new ItemResult($jobId, false, $msg)
            ];
        }

        // 2. Normalización de Datos
        // La API puede devolver los datos directamente en la raíz (array) o dentro de 'data'
        // Si es un array secuencial (lista de reservas), lo usamos directo.
        $bookingsData = $apiResponse;

        if (isset($apiResponse['data']) && is_array($apiResponse['data'])) {
            $bookingsData = $apiResponse['data'];
        }
        // Caso borde: Si devuelve un solo objeto no envuelto en array (raro en GET masivo, pero posible)
        elseif (isset($apiResponse['id']) && !isset($apiResponse[0])) {
            $bookingsData = [$apiResponse];
        }

        $count = count($bookingsData);

        // 3. Retorno Exitoso
        // Pasamos TODO el array de reservas en 'extraData'.
        // El Handler se encargará de iterarlas y persistirlas una por una.
        return [
            $jobId => new ItemResult(
                queueItemId: $jobId,
                success: true,
                message: "Descarga completada: $count reservas recuperadas.",
                remoteId: null,
                extraData: $bookingsData // Array crudo de reservas para el Handler
            )
        ];
    }
}