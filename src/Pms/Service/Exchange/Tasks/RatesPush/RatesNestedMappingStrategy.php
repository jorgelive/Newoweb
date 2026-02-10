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

        foreach ($batch->getItems() as $item) {
            /** @var PmsRatesPushQueue $item */
            $map = $item->getUnidadBeds24Map();
            if (!$map) {
                continue;
            }

            $roomId = (int) $map->getBeds24RoomId();
            $precioOriginal = (string) $item->getPrecio();
            $monedaItem = $item->getMoneda();

            // --- LÓGICA DE CONVERSIÓN MONETARIA (PEN -> USD) ---
            // Beds24 solo acepta USD. Si la moneda es Soles, dividimos por el TC Promedio.
            $precioFinal = (float) $precioOriginal;

            // Usamos la Clave Natural (ID) para MaestroMoneda
            if ($monedaItem && $monedaItem->getId() === MaestroMoneda::DB_ID_SOL) {
                $fechaTarifa = $item->getFechaInicio() ?? new \DateTime();
                $tc = $this->tipocambioManager->getTipodecambio($fechaTarifa);

                if ($tc) {
                    $promedioStr = (string)$tc->getPromedio();

                    // Verificación de seguridad para evitar división por cero
                    if ($promedioStr !== '0' && $promedioStr !== '0.000') {
                        // bcdiv garantiza que no perdamos céntimos en la conversión
                        $precioConvertido = bcdiv($precioOriginal, $promedioStr, 2);
                        $precioFinal = (float) $precioConvertido;
                    }
                }
            }

            // Estructura Beds24 v2: roomId -> calendar [from, to, price...]
            $grouped[] = [
                'roomId'   => $roomId,
                'calendar' => [[
                    'from'    => $item->getFechaInicio()?->format('Y-m-d'),
                    'to'      => $item->getFechaFin()?->format('Y-m-d'),
                    'price'   => $precioFinal,
                    'minStay' => (int) $item->getMinStay(),
                ]]
            ];

            // Correlación para identificar qué ID de cola corresponde a qué respuesta de la API
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

        // 1. Manejo de Error Global
        if (isset($apiResponse['success']) && $apiResponse['success'] === false && !isset($apiResponse[0])) {
            $msg = $apiResponse['message'] ?? 'Error global en Batch Rates';
            foreach ($mapping->correlationMap as $queueId) {
                $results[$queueId] = new ItemResult($queueId, false, $msg);
            }
            return $results;
        }

        // 2. Correlación Posicional Estricta
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

            // 'modified' contiene el detalle de lo que realmente se cambió en Beds24
            $extra = $respItem['modified'] ?? $respItem;

            $results[$queueId] = new ItemResult(
                queueItemId: $queueId,
                success: $success,
                message: $errorMsg,
                remoteId: null, // Rates no suelen tener un "ID de tasa" único de retorno
                extraData: (array)$extra
            );
        }

        return $results;
    }
}