<?php

declare(strict_types=1);

namespace App\Pms\Service\Exchange\Tasks\RatesPush;

use App\Exchange\Service\Common\HomogeneousBatch;
use App\Exchange\Service\Mapping\ItemResult;
use App\Exchange\Service\Mapping\MappingResult;
use App\Exchange\Service\Mapping\MappingStrategyInterface;
use App\Entity\Maestro\MaestroMoneda;
use App\Pms\Entity\PmsRatesPushQueue;
use App\Service\TipocambioManager;

/**
 * Estrategia de mapeo para el envío de tarifas (Rates) a Beds24.
 * * Realiza conversión automática PEN -> USD mediante BCMath.
 * * Filtra/ajusta fechas en el pasado (Beds24 rechaza "invalid dates").
 * * Agrupa ítems para optimización de API Batch.
 * * Mantiene trazabilidad posicional para la respuesta.
 */
final readonly class RatesNestedMappingStrategy implements MappingStrategyInterface
{
    /**
     * @param TipocambioManager $tipocambioManager Servicio para obtener el TC oficial.
     */
    public function __construct(
        private TipocambioManager $tipocambioManager
    ) {}

    /**
     * Transforma el lote de la base de datos al payload esperado por Beds24.
     */
    public function map(HomogeneousBatch $batch): MappingResult
    {
        $config = $batch->getConfig();
        $endpoint = $batch->getEndpoint();

        $fullUrl = rtrim($config->getBaseUrl(), '/') . '/' . ltrim((string)$endpoint->getEndpoint(), '/');

        $grouped = [];
        $correlationMap = [];
        $currentIndex = 0;
        $skippedIndex = 0;

        $today = new \DateTimeImmutable('today');

        foreach ($batch->getItems() as $item) {
            /** @var PmsRatesPushQueue $item */
            $map = $item->getUnidadBeds24Map();
            if (!$map) {
                continue;
            }

            // --- 1. VALIDACIÓN Y AJUSTE DE FECHAS (Escudo anti "invalid dates") ---
            $fechaInicioOriginal = $item->getFechaInicio();

            $fechaInicio = $fechaInicioOriginal ? \DateTimeImmutable::createFromInterface($fechaInicioOriginal)->setTime(0, 0) : $today;
            $fechaFin    = $item->getFechaFin() ? \DateTimeImmutable::createFromInterface($item->getFechaFin())->setTime(0, 0) : $fechaInicio;

            // Si empieza antes de hoy, la forzamos a hoy
            if ($fechaInicio < $today) {
                $fechaInicio = clone $today;
            }

            // Si la fecha fin es anterior a hoy, el rango completo caducó.
            // Lo omitimos del payload de Beds24 pero lo guardamos en el mapa para marcarlo como procesado.
            if ($fechaFin < $today) {
                $correlationMap['skipped_' . $skippedIndex++] = (string) $item->getId();
                continue;
            }

            // --- 2. LÓGICA DE CONVERSIÓN MONETARIA (PEN -> USD) ---
            $roomId = (int) $map->getBeds24RoomId();
            $precioOriginal = (string) $item->getPrecio();
            $monedaItem = $item->getMoneda();
            $precioFinal = (float) $precioOriginal;

            if ($monedaItem && $monedaItem->getId() === MaestroMoneda::DB_ID_SOL) {
                // Usamos la fecha original para obtener el TC exacto de ese día
                $fechaTarifa = $fechaInicioOriginal ?? new \DateTime();
                $tc = $this->tipocambioManager->getTipodecambio($fechaTarifa);

                if ($tc) {
                    $promedioStr = (string)$tc->getPromedio();

                    if ($promedioStr !== '0' && $promedioStr !== '0.000') {
                        $precioConvertido = bcdiv($precioOriginal, $promedioStr, 2);
                        $precioFinal = (float) $precioConvertido;
                    }
                }
            }

            // --- 3. ARMADO DEL PAYLOAD BEDS24 ---
            $grouped[] = [
                'roomId'   => $roomId,
                'calendar' => [[
                    'from'    => $fechaInicio->format('Y-m-d'),
                    'to'      => $fechaFin->format('Y-m-d'),
                    'price'   => $precioFinal,
                    'minStay' => (int) $item->getMinStay(),
                ]]
            ];

            // Correlación estricta para el índice numérico
            $correlationMap[$currentIndex] = (string) $item->getId();
            $currentIndex++;
        }

        return new MappingResult(
            method: (string)$endpoint->getMetodo(),
            fullUrl: $fullUrl,
            payload: $grouped,
            config: $config,
            correlationMap: $correlationMap
        );
    }

    /**
     * Procesa la respuesta de Beds24 y la mapea de vuelta a los IDs de nuestra cola.
     */
    public function parseResponse(array $apiResponse, MappingResult $mapping): array
    {
        $results = [];

        // 1. Manejo de Error Global de la API
        if (isset($apiResponse['success']) && $apiResponse['success'] === false && !isset($apiResponse[0])) {
            $msg = $apiResponse['message'] ?? 'Error global en Batch Rates';
            foreach ($mapping->correlationMap as $queueId) {
                $results[$queueId] = new ItemResult($queueId, false, $msg);
            }
            return $results;
        }

        // 2. Correlación Posicional Estricta (Para los que sí viajaron en el payload)
        foreach ($apiResponse as $index => $respItem) {
            if (!isset($mapping->correlationMap[$index])) {
                continue;
            }

            $queueId = $mapping->correlationMap[$index];
            $success = (bool)($respItem['success'] ?? false);

            $errorMsg = null;
            if (!$success) {
                $errorMsg = $respItem['message'] ?? 'Error desconocido en habitación';
                if (isset($respItem['errors'][0]['message'])) {
                    $errorMsg = $respItem['errors'][0]['message'];
                }
            }

            $extra = $respItem['modified'] ?? $respItem;

            $results[$queueId] = new ItemResult(
                queueItemId: $queueId,
                success: $success,
                message: $errorMsg,
                remoteId: null,
                extraData: (array)$extra
            );
        }

        // 3. Resolución de ítems omitidos (Fechas en el pasado)
        // Recorremos el correlationMap buscando nuestras llaves mágicas "skipped_X"
        foreach ($mapping->correlationMap as $key => $queueId) {
            if (is_string($key) && str_starts_with($key, 'skipped_')) {
                $results[$queueId] = new ItemResult(
                    queueItemId: $queueId,
                    success: true, // Lo marcamos como exitoso para que Doctrine lo elimine de la cola de pendientes
                    message: 'Omitido automáticamente: Rango de fechas en el pasado',
                    remoteId: null,
                    extraData: ['action' => 'skipped']
                );
            }
        }

        return $results;
    }
}