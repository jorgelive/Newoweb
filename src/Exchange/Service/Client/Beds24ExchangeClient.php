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

        $allDecodedData = null;
        $finalStatusCode = 200;

        $currentUrl = $mapping->fullUrl;
        $currentPayload = $mapping->payload; // Para GET va en query, para POST en json

        try {
            // 🔥 EL BUCLE TRANSPARENTE DE PAGINACIÓN
            do {
                $options = [
                    'headers' => array_merge(
                        $this->authService->getAuthHeaders($config),
                        ['Accept' => 'application/json', 'Content-Type' => 'application/json']
                    ),
                    ($mapping->method === 'GET' ? 'query' : 'json') => $currentPayload
                ];

                $response = $this->httpClient->request($mapping->method, $currentUrl, $options);

                $rawContent = $response->getContent(false);
                $finalStatusCode = $response->getStatusCode(); // Guardamos el status de la última iteración

                // FIX UTF-8: Forzamos la codificación correcta para salvar Emojis (📍, 🏠)
                $currentEncoding = mb_detect_encoding($rawContent, 'UTF-8, ISO-8859-1', true);
                if ($currentEncoding !== 'UTF-8') {
                    $rawContent = mb_convert_encoding($rawContent, 'UTF-8', $currentEncoding ?: 'ISO-8859-1');
                }

                // Decodificación Segura de la página actual
                $decoded = [];
                try {
                    $decoded = json_decode($rawContent, true, 512, JSON_THROW_ON_ERROR);
                } catch (\Throwable) {
                    // Fallo silencioso en decodificación (ej: error 504 HTML), rompe el bucle
                    $hasNextPage = false;
                }

                // 🏗️ FUSIÓN DE DATOS (Merge)
                if ($allDecodedData === null) {
                    // Es la primera página, inicializamos el objeto maestro
                    $allDecodedData = $decoded;
                } else {
                    // Son páginas siguientes, solo agregamos los items al array 'data'
                    if (isset($decoded['data']) && is_array($decoded['data'])) {
                        $allDecodedData['data'] = array_merge($allDecodedData['data'] ?? [], $decoded['data']);
                    }
                }

                // 🧭 EVALUAR PAGINACIÓN (Solo aplica para peticiones GET que tengan nextPageExists)
                $hasNextPage = false;
                if ($mapping->method === 'GET'
                    && isset($decoded['pages']['nextPageExists'])
                    && $decoded['pages']['nextPageExists'] === true
                    && !empty($decoded['pages']['nextPageLink'])
                ) {
                    $hasNextPage = true;
                    $currentUrl = $decoded['pages']['nextPageLink'];

                    // IMPORTANTE: Al usar el nextPageLink, Beds24 ya incluye los query parameters originales
                    // (ej: ?status=confirmed&page=2). Debemos vaciar el payload para que Symfony no los duplique.
                    $currentPayload = [];
                }

            } while ($hasNextPage);

            // 📦 Para la auditoría (LastResponseRaw), re-codificamos el array combinado
            // Así en la base de datos podrás ver todo lo que se procesó en un solo JSON.
            $finalRawContent = json_encode($allDecodedData, JSON_UNESCAPED_UNICODE);

            return new ExchangeNetworkResult($allDecodedData ?? [], $finalRawContent, $finalStatusCode);

        } catch (\Throwable $e) {
            $this->logger->error("Beds24 Client Error: " . $e->getMessage(), ['url' => $currentUrl]);
            throw $e;
        }
    }
}