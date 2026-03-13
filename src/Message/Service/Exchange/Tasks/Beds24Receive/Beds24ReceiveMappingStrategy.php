<?php
declare(strict_types=1);

namespace App\Message\Service\Exchange\Tasks\Beds24Receive;

use App\Exchange\Service\Common\HomogeneousBatch;
use App\Exchange\Service\Mapping\ItemResult;
use App\Exchange\Service\Mapping\MappingResult;
use App\Exchange\Service\Mapping\MappingStrategyInterface;

final readonly class Beds24ReceiveMappingStrategy implements MappingStrategyInterface
{
    public function map(HomogeneousBatch $batch): MappingResult
    {
        $config = $batch->getConfig();
        $endpoint = $batch->getEndpoint();

        // Sabemos que el lote es estrictamente 1
        $job = $batch->getItems()[0];

        $fullUrl = rtrim($config->getBaseUrl(), '/') . '/' . ltrim((string)$endpoint->getEndpoint(), '/');

        return new MappingResult(
            method: (string)$endpoint->getMetodo(),
            fullUrl: $fullUrl,
            payload: ['bookingId' => $job->getTargetBookId()], // Symfony lo enviará limpio
            config: $config,
            correlationMap: ['job' => (string)$job->getId()]
        );
    }

    public function parseResponse(array $apiResponse, MappingResult $mapping): array
    {
        $jobId = $mapping->correlationMap['job'];

        // Manejo de error estructurado desde la API
        if (isset($apiResponse['success']) && $apiResponse['success'] === false) {
            $msg = $apiResponse['message'] ?? 'Error desconocido desde Beds24';
            return [$jobId => new ItemResult($jobId, false, $msg)];
        }

        // Extraer los datos reales
        $messagesData = $apiResponse['data'] ?? [];

        return [
            $jobId => new ItemResult(
                queueItemId: $jobId,
                success: true,
                message: null,
                remoteId: null,
                extraData: $messagesData
            )
        ];
    }
}