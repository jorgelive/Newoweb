<?php
declare(strict_types=1);

namespace App\Pms\Service\Exchange\Client;

use App\Exchange\Service\Common\ExchangeNetworkResult;
use App\Exchange\Service\Contract\ExchangeClientInterface;
use App\Exchange\Service\Mapping\MappingResult;
use App\Pms\Entity\Beds24Config;
use App\Pms\Service\Exchange\Auth\Beds24AuthService;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Beds24ExchangeClient implements ExchangeClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly Beds24AuthService $authService,
        private readonly LoggerInterface $logger
    ) {}

    public static function getClientAlias(): string { return 'beds24'; }

    public function send(MappingResult $mapping): ExchangeNetworkResult
    {
        // 1. Validación de Tipo (Type Guard)
        // Convertimos la interfaz genérica a la clase concreta que necesita el Auth Service
        $config = $mapping->config;

        if (!$config instanceof Beds24Config) {
            throw new InvalidArgumentException(sprintf(
                'Beds24ExchangeClient requiere una instancia de Beds24Config, se recibió: %s',
                get_debug_type($config)
            ));
        }

        try {
            $options = [
                'headers' => array_merge(
                // Ahora pasamos la variable $config validada
                    $this->authService->getAuthHeaders($config),
                    ['Accept' => 'application/json', 'Content-Type' => 'application/json']
                ),
                ($mapping->method === 'GET' ? 'query' : 'json') => $mapping->payload
            ];

            $response = $this->httpClient->request($mapping->method, $mapping->fullUrl, $options);

            // 2. Captura de Auditoría (Raw Body)
            // Obtenemos el contenido sin lanzar excepciones todavía (false)
            $rawContent = $response->getContent(false);
            $statusCode = $response->getStatusCode();

            // 3. Decodificación Segura
            try {
                $decoded = $response->toArray(false);
            } catch (\Throwable) {
                // Si falla el JSON (ej: error 504 Gateway Timeout HTML), devolvemos array vacío
                // pero conservamos el $rawContent para que el log muestre el error HTML real.
                $decoded = [];
            }

            return new ExchangeNetworkResult($decoded, $rawContent, $statusCode);

        } catch (\Throwable $e) {
            $this->logger->error("Beds24 Client Error: " . $e->getMessage(), ['url' => $mapping->fullUrl]);
            throw $e;
        }
    }
}