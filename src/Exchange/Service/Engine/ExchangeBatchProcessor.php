<?php

declare(strict_types=1);

namespace App\Exchange\Service\Engine;

use App\Exchange\Service\Common\HomogeneousBatch;
use App\Exchange\Service\Contract\ExchangeClientInterface;
use App\Exchange\Service\Contract\ExchangeTaskInterface;
use App\Exchange\Service\Mapping\ItemResult;
use Symfony\Component\DependencyInjection\Attribute\TaggedLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class ExchangeBatchProcessor
{
    /**
     * @param ServiceLocator<ExchangeClientInterface> $clientLocator Los clientes de canal, por alias.
     */
    public function __construct(
        #[TaggedLocator('app.exchange.client', defaultIndexMethod: 'getClientAlias')]
        private readonly ServiceLocator $clientLocator
    ) {}

    /**
     * @return array<array-key, ItemResult> Lo que devuelva `parseResponse()` de la estrategia:
     *                                      un resultado por ítem, indexado por queueItemId.
     */
    public function processBatch(ExchangeTaskInterface $task, HomogeneousBatch $batch): array
    {
        $clientAlias = $batch->getConfig()->getProviderName();
        if (!$this->clientLocator->has($clientAlias)) {
            throw new \RuntimeException("No client found for provider: $clientAlias");
        }

        /** @var ExchangeClientInterface $client */
        $client = $this->clientLocator->get($clientAlias);
        $mapping = $task->getMappingStrategy()->map($batch);

        // 1. Auditoría del REQUEST
        //
        // Se guarda método + URL + payload, no sólo el payload: en un GET los parámetros
        // pueden ir en la URL (los pull de Beds24 construyen ahí el `bookingId` repetido,
        // §11.3.1) y la auditoría se quedaba en un `[]` que no decía nada. Con la URL queda
        // registrado qué se pidió exactamente, que es lo que se busca al depurar.
        //
        // La autenticación de Beds24 viaja en cabeceras, así que aquí no se filtra ningún token.
        $jsonRequest = json_encode([
            'method'  => $mapping->method,
            'url'     => $mapping->fullUrl,
            'payload' => $mapping->payload,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            // Para auditar la petición: si no se puede serializar se guarda el motivo, no
            // un `false` que en la columna se ve igual que «no se mandó nada».
            ?: '{"_error":"la petición no se pudo serializar"}';

        foreach ($batch->getItems() as $item) {
            $item->setLastRequestRaw($jsonRequest);
        }

        // 2. Envío y Recepción
        $networkResult = $client->send($mapping);

        // 3. Auditoría del RESPONSE (RAW antes de Parse)
        foreach ($batch->getItems() as $item) {
            $item->setLastResponseRaw($networkResult->rawBody);
            $item->setLastHttpCode($networkResult->statusCode);
        }

        // 4. Parsing de negocio
        return $task->getMappingStrategy()->parseResponse($networkResult->decodedData, $mapping);
    }
}