<?php

declare(strict_types=1);

namespace App\Message\Service\Exchange\Tasks\GupshupSend;

use App\Exchange\Service\Common\HomogeneousBatch;
use App\Exchange\Service\Mapping\ItemResult;
use App\Exchange\Service\Mapping\MappingResult;
use App\Exchange\Service\Mapping\MappingStrategyInterface;
use App\Message\Entity\GupshupSendQueue;

final readonly class GupshupSendMappingStrategy implements MappingStrategyInterface
{
    public function map(HomogeneousBatch $batch): MappingResult
    {
        $config = $batch->getConfig();
        $endpoint = $batch->getEndpoint();

        // Gupshup suele pedir el 'source' (número de origen) y 'src.name' (App Name)
        // Asumimos que están en las credenciales JSON del Config.
        $creds = $config->getCredentials();
        $sourcePhone = $creds['source_number'] ?? null;
        $appName = $creds['app_name'] ?? 'MyBedsApp';

        $fullUrl = rtrim($config->getBaseUrl(), '/') . '/' . ltrim((string)$endpoint->getEndpoint(), '/');
        $method = strtoupper((string)$endpoint->getMetodo());

        $payload = [];
        $correlation = [];

        foreach ($batch->getItems() as $index => $item) {
            /** @var GupshupSendQueue $item */
            $msg = $item->getMessage();

            // Lógica de contenido:
            // 1. ¿Es Template? (Si el mensaje tiene metadata de template)
            // 2. ¿Es Texto Libre?
            $content = $msg->getContentTranslated() ?? $msg->getContentOriginal();
            $destination = $item->getDestinationPhone(); // Ya debe venir limpio del persistidor

            // Construcción del Payload Individual (Ejemplo API Single Message)
            // Si la API soporta Batch real, esto sería un array de objetos.
            // Si la API es 1 a 1, el BatchProcessor enviará N peticiones.

            // Asumimos estructura estándar de Gupshup Enterprise:
            $messagePayload = [
                'channel' => 'whatsapp',
                'source' => $sourcePhone,
                'destination' => $destination,
                'src.name' => $appName,
                'message' => json_encode([
                    'type' => 'text',
                    'text' => $content
                ])
            ];

            // Si tienes lógica de Templates, aquí iría el switch case.

            $payload[] = $messagePayload;
            $correlation[$index] = (string) $item->getId();
        }

        // NOTA: Si la API de Gupshup no soporta Batch JSON nativo (array de mensajes),
        // y tienes que enviar 1 a 1, tu 'ExchangeClient' debe saber manejar arrays en el payload
        // iterando internamente, O configuras 'getMaxBatchSize' a 1 en la Tarea.

        return new MappingResult(
            method: $method,
            fullUrl: $fullUrl,
            payload: $payload,
            config: $config,
            correlationMap: $correlation
        );
    }

    public function parseResponse(array $apiResponse, MappingResult $mapping): array
    {
        $results = [];

        // Gupshup Response suele ser:
        // { "status": "submitted", "messageId": "UUID..." }
        // O si es batch, un array de lo anterior.

        // Si la respuesta es un solo objeto (caso batch size = 1)
        if (isset($apiResponse['messageId']) || isset($apiResponse['status'])) {
            $apiResponse = [$apiResponse];
        }

        foreach ($apiResponse as $index => $respData) {
            if (!isset($mapping->correlationMap[$index])) continue;

            $queueId = $mapping->correlationMap[$index];

            // Gupshup devuelve 'submitted' o 'queued' como éxito inmediato
            $status = $respData['status'] ?? 'error';
            $success = in_array($status, ['submitted', 'queued', 'sent', 'success']);

            $remoteId = $respData['messageId'] ?? null;
            $errorMsg = $success ? null : ($respData['message'] ?? 'Error Gupshup');

            $results[$queueId] = new ItemResult(
                queueItemId: $queueId,
                success: $success,
                message: $errorMsg,
                remoteId: $remoteId,
                extraData: (array)$respData
            );
        }

        return $results;
    }
}