<?php

declare(strict_types=1);

namespace App\Message\Service\Exchange\Tasks\Beds24Send;

use App\Exchange\Service\Common\HomogeneousBatch;
use App\Exchange\Service\Mapping\ItemResult;
use App\Exchange\Service\Mapping\MappingResult;
use App\Exchange\Service\Mapping\MappingStrategyInterface;
use App\Message\Entity\Beds24SendQueue;
use App\Message\Entity\Message;

final readonly class Beds24SendMappingStrategy implements MappingStrategyInterface
{
    public function map(HomogeneousBatch $batch): MappingResult
    {
        $config = $batch->getConfig();
        $endpoint = $batch->getEndpoint();

        $fullUrl = rtrim($config->getBaseUrl(), '/') . '/' . ltrim((string)$endpoint->getEndpoint(), '/');
        $method = strtoupper((string)$endpoint->getMetodo());

        $payload = [];
        $correlation = [];

        foreach ($batch->getItems() as $index => $item) {
            /** @var Beds24SendQueue $item */
            $msg = $item->getMessage();

            if (!$msg instanceof Message) {
                continue;
            }

            // Usamos el contenido traducido si existe, si no el original
            $text = $msg->getContentExternal() ?? $msg->getContentExternal();

            // Construimos el payload específico para Beds24 Messages
            // Nota: Esto depende de la especificación exacta de tu Endpoint en BD.
            // Asumimos formato estándar V2 para postear mensajes.
            $payload[] = [
                'bookId' => $item->getTargetBookId(), // ID de reserva de Beds24
                'message' => $text,
                // Opcional: 'subject' => ...
            ];

            // Mapa de correlación: Índice del array => ID de la cola
            $correlation[$index] = (string)$item->getId();
        }

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

        // Beds24 devuelve un array de resultados posicionales
        foreach ($apiResponse as $index => $respData) {

            if (!isset($mapping->correlationMap[$index])) {
                continue;
            }

            $queueId = $mapping->correlationMap[$index];

            // Determinamos éxito
            $success = (bool)($respData['success'] ?? false);
            $errorMsg = null;

            if (!$success) {
                $errorMsg = $respData['message'] ?? 'Error desconocido al enviar mensaje';
            }

            // A veces Beds24 devuelve un ID del mensaje creado
            $remoteId = $respData['id'] ?? $respData['new']['id'] ?? null;

            $results[$queueId] = new ItemResult(
                queueItemId: $queueId,
                success: $success,
                message: $errorMsg,
                remoteId: $remoteId ? (string)$remoteId : null,
                extraData: (array)$respData
            );
        }

        return $results;
    }
}