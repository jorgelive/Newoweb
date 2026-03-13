<?php
declare(strict_types=1);

namespace App\Exchange\Service\Client;

use App\Exchange\Entity\Beds24Config;
use App\Exchange\Service\Auth\Beds24AuthService;
use App\Exchange\Service\Common\ExchangeNetworkResult;
use App\Exchange\Service\Contract\ExchangeClientInterface;
use App\Exchange\Service\Mapping\MappingResult;
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

    public static function getClientAlias(): string {
        return 'beds24';
    }

    public function send(MappingResult $mapping): ExchangeNetworkResult
    {
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
                    $this->authService->getAuthHeaders($config),
                    ['Accept' => 'application/json', 'Content-Type' => 'application/json']
                ),
                ($mapping->method === 'GET' ? 'query' : 'json') => $mapping->payload
            ];

            $response = $this->httpClient->request($mapping->method, $mapping->fullUrl, $options);

            // 1. Obtenemos el texto crudo
            $rawContent = $response->getContent(false);
            $statusCode = $response->getStatusCode();

            // 🔥 2. FIX UTF-8: Forzamos la codificación correcta para salvar Emojis (📍, 🏠)
            $currentEncoding = mb_detect_encoding($rawContent, 'UTF-8, ISO-8859-1', true);
            if ($currentEncoding !== 'UTF-8') {
                $rawContent = mb_convert_encoding($rawContent, 'UTF-8', $currentEncoding ?: 'ISO-8859-1');
            }

            // 3. Decodificación Segura
            try {
                $decoded = json_decode($rawContent, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                // Si falla (ej: error 504 Gateway Timeout HTML), devolvemos array vacío
                $decoded = [];
            }

            return new ExchangeNetworkResult($decoded, $rawContent, $statusCode);

        } catch (\Throwable $e) {
            $this->logger->error("Beds24 Client Error: " . $e->getMessage(), ['url' => $mapping->fullUrl]);
            throw $e;
        }
    }
}