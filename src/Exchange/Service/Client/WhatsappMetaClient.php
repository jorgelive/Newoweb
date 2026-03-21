<?php

declare(strict_types=1);

namespace App\Exchange\Service\Client;

use App\Exchange\Entity\ExchangeEndpoint;
use App\Exchange\Entity\MetaConfig;
use App\Exchange\Service\Common\ExchangeNetworkResult;
use App\Exchange\Service\Contract\ExchangeClientInterface;
use App\Exchange\Service\Mapping\MappingResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

#[AutoconfigureTag('app.exchange.client')]
final class WhatsappMetaClient implements ExchangeClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient
    ) {}

    /**
     * @inheritDoc
     */
    public static function getClientAlias(): string
    {
        return 'meta';
    }

    /**
     * @inheritDoc
     */
    public function send(MappingResult $mapping): ExchangeNetworkResult
    {
        $apiKey = $mapping->config->getCredential('apiKey');

        if (!$apiKey) {
            throw new \RuntimeException('La API Key (Token permanente) no está configurada en MetaConfig.');
        }

        $responses = [];
        $rawBodies = [];
        $lastStatusCode = 200;

        // Peticiones asíncronas concurrentes gracias a Symfony HttpClient
        $httpResponses = [];

        foreach ($mapping->payload as $index => $singlePayload) {
            $httpResponses[$index] = $this->httpClient->request(
                $mapping->method,
                $mapping->fullUrl,
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Content-Type'  => 'application/json',
                    ],
                    'json' => $singlePayload,
                ]
            );
        }

        // Resolución de promesas
        foreach ($httpResponses as $index => $response) {
            try {
                $statusCode = $response->getStatusCode();
                $lastStatusCode = $statusCode;

                // false: evita lanzar excepción en 4xx/5xx para capturar el JSON del error de Meta
                $content = $response->getContent(false);

                // Decodificamos temporalmente el RAW para que el JSON final de auditoría quede limpio
                $rawBodies[$index] = json_decode($content, true) ?? $content;

                $decoded = json_decode($content, true) ?? [];

                if (isset($decoded['error'])) {
                    $responses[$index] = [
                        'status' => 'error',
                        'message' => $decoded['error']['message'] ?? 'Error de Meta API',
                        'error_code' => $decoded['error']['code'] ?? null,
                    ];
                } else {
                    $responses[$index] = [
                        'status' => 'success',
                        'messageId' => $decoded['messages'][0]['id'] ?? null,
                        'raw' => $decoded
                    ];
                }
            } catch (Throwable $e) {
                $lastStatusCode = 500;
                $rawBodies[$index] = $e->getMessage();
                $responses[$index] = [
                    'status' => 'error',
                    'message' => 'HTTP Exception: ' . $e->getMessage()
                ];
            }
        }

        $finalRawContent = json_encode($rawBodies, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new ExchangeNetworkResult($responses, $finalRawContent, $lastStatusCode);
    }

    /**
     * Obtiene las plantillas aprobadas directamente desde la Graph API de Meta.
     * Reemplaza dinámicamente el marcador {wabaId} en el path del endpoint configurado en la BD.
     * * @param MetaConfig $config Configuración que contiene las credenciales.
     * @param ExchangeEndpoint $endpoint El endpoint mapeado (ej: {wabaId}/message_templates).
     * @return array El array asociativo con la clave 'data' que contiene las plantillas.
     * @throws \RuntimeException Si faltan credenciales o la API responde con error.
     */
    public function fetchTemplates(MetaConfig $config, ExchangeEndpoint $endpoint): array
    {
        $apiKey = $config->getCredential('apiKey');
        $wabaId = $config->getCredential('wabaId');

        if (!$apiKey || !$wabaId) {
            throw new \RuntimeException(sprintf('La configuración de Meta [%s] no tiene API Key o WABA ID.', $config->getNombre()));
        }

        // ESTRATEGIA PRO: URI Templating
        $dynamicPath = str_replace('{wabaId}', (string)$wabaId, (string)$endpoint->getEndpoint());

        // Construcción de la URL: Base(v22.0) + Path Dinámico
        $url = sprintf(
            '%s/%s',
            rtrim((string)$config->getBaseUrlRaw(), '/'),
            ltrim($dynamicPath, '/')
        );

        $response = $this->httpClient->request(strtoupper($endpoint->getMetodo()), $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
            ],
            'query' => [
                'limit' => 500 // Aseguramos traer la lista completa
            ]
        ]);

        $content = $response->getContent(false);
        $decoded = json_decode($content, true);

        if ($response->getStatusCode() >= 400) {
            $errorMsg = $decoded['error']['message'] ?? 'Error desconocido sincronizando plantillas de Meta.';
            throw new \RuntimeException('Meta API Error: ' . $errorMsg);
        }

        return $decoded ?? [];
    }
}