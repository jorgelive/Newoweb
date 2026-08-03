<?php

declare(strict_types=1);

namespace App\Pms\Service\Exchange\Tasks\InvoiceReceive;

use App\Exchange\Service\Common\HomogeneousBatch;
use App\Exchange\Service\Mapping\ItemResult;
use App\Exchange\Service\Mapping\MappingResult;
use App\Exchange\Service\Mapping\MappingStrategyInterface;

final readonly class Beds24InvoiceReceiveMappingStrategy implements MappingStrategyInterface
{
    public function map(HomogeneousBatch $batch): MappingResult
    {
        $config = $batch->getConfig();
        $endpoint = $batch->getEndpoint();

        // El lote es estrictamente 1 (getMaxBatchSize()).
        $job = $batch->getItems()[0];

        $fullUrl = rtrim($config->getBaseUrl(), '/') . '/' . ltrim((string) $endpoint->getEndpoint(), '/');

        return new MappingResult(
            method: (string) $endpoint->getMetodo(),
            fullUrl: $fullUrl,
            payload: ['bookingId' => $job->getTargetBookId()], // Symfony lo envía como query en GET
            config: $config,
            correlationMap: ['job' => (string) $job->getId()]
        );
    }

    public function parseResponse(array $apiResponse, MappingResult $mapping): array
    {
        $jobId = $mapping->correlationMap['job'];

        // Error estructurado desde la API
        if (isset($apiResponse['success']) && $apiResponse['success'] === false) {
            $msg = $apiResponse['message'] ?? 'Error desconocido desde Beds24';
            return [$jobId => new ItemResult($jobId, false, $msg)];
        }

        // 🔥 GOTCHA: a diferencia de los mensajes (cuyo 'data' ya es una lista plana de
        // mensajes), aquí 'data' es una lista de FACTURAS, cada una con su propio arreglo
        // 'invoiceItems'. Aplanamos a una lista de invoiceItems propagando invoiceId /
        // invoiceDate de la factura padre a cada línea (el webhook no los trae).
        $facturas = $apiResponse['data'] ?? [];
        $items = [];

        foreach ($facturas as $factura) {
            if (!is_array($factura)) {
                continue;
            }

            $invoiceId = $factura['invoiceId'] ?? null;
            $invoiceDate = $factura['invoiceDate'] ?? null;
            $lineas = $factura['invoiceItems'] ?? [];

            foreach ($lineas as $linea) {
                if (!is_array($linea)) {
                    continue;
                }
                // Propagamos la cabecera de la factura si la línea no la trae.
                $linea['invoiceId']   ??= $invoiceId;
                $linea['invoiceDate'] ??= $invoiceDate;
                $items[] = $linea;
            }
        }

        return [
            $jobId => new ItemResult(
                queueItemId: $jobId,
                success: true,
                message: null,
                remoteId: null,
                extraData: $items
            )
        ];
    }
}
