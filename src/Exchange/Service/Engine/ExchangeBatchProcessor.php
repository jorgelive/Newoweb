<?php
declare(strict_types=1);

namespace App\Exchange\Service\Engine;

use App\Exchange\Service\Common\HomogeneousBatch;
use App\Exchange\Service\Contract\ExchangeClientInterface;
use App\Exchange\Service\Contract\ExchangeTaskInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class ExchangeBatchProcessor
{
    public function __construct(private readonly ServiceLocator $clientLocator) {}

    public function processBatch(ExchangeTaskInterface $task, HomogeneousBatch $batch): array
    {
        $clientAlias = $batch->getConfig()->getProviderName();
        if (!$this->clientLocator->has($clientAlias)) {
            throw new \RuntimeException("No client found for provider: $clientAlias");
        }

        /** @var ExchangeClientInterface $client */
        $client = $this->clientLocator->get($clientAlias);
        $mapping = $task->getMappingStrategy()->map($batch);

        // 1. Auditoría del REQUEST (Esto ya funcionaba)
        $jsonRequest = json_encode($mapping->payload);
        foreach ($batch->getItems() as $item) {
            $item->setLastRequestRaw($jsonRequest);
        }

        // 2. Envío y Recepción del PAQUETE COMPLETO
        $networkResult = $client->send($mapping);

        // 3. Auditoría del RESPONSE (¡AQUÍ ESTÁ EL ARREGLO!)
        // Asignamos el Raw y el Status Code a CADA ítem del lote antes de procesar
        foreach ($batch->getItems() as $item) {
            $item->setLastResponseRaw($networkResult->rawBody);
            $item->setLastHttpCode($networkResult->statusCode);
        }

        // 4. Parsing de negocio (usando solo la parte 'decodedData')
        return $task->getMappingStrategy()->parseResponse($networkResult->decodedData, $mapping);
    }
}