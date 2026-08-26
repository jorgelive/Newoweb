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

    /**
     * PUSH DE DEFINICIÓN: Envía el JSON estructural de una plantilla para revisión de Meta.
     */
    public function pushTemplateDefinition(MetaConfig $config, ExchangeEndpoint $endpoint, array $templatePayload): array
    {
        $apiKey = $config->getCredential('apiKey') ?? $config->getApiKey();
        $wabaId = $config->getCredential('wabaId');

        if (!$apiKey || !$wabaId) {
            throw new \RuntimeException(sprintf('La configuración de Meta [%s] no tiene API Key o WABA ID para hacer Push.', $config->getNombre()));
        }

        $dynamicPath = str_replace('{wabaId}', (string)$wabaId, (string)$endpoint->getEndpoint());
        $url = sprintf('%s/%s', rtrim((string)$config->getBaseUrlRaw(), '/'), ltrim($dynamicPath, '/'));

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
            ],
            'json' => $templatePayload
        ]);

        $content = $response->getContent(false);
        $decoded = json_decode($content, true);

        if ($response->getStatusCode() >= 400) {
            // 🔥 EXTRACCIÓN PROFUNDA DEL ERROR DE META 🔥
            $baseError = $decoded['error']['message'] ?? 'Error desconocido';
            $userMsg = $decoded['error']['error_user_msg'] ?? '';
            $details = $decoded['error']['error_data']['details'] ?? '';

            $detailedError = $baseError;
            if ($userMsg) {
                $detailedError .= ' | ' . $userMsg;
            }
            if ($details) {
                $detailedError .= ' | Detalles: ' . $details;
            }

            throw new \RuntimeException($detailedError);
        }

        return $decoded ?? [];
    }

    /**
     * EDITAR DEFINICIÓN: Actualiza los componentes de un idioma existente directamente por su ID.
     * A diferencia del Push, aquí Meta solo permite enviar la llave 'components'.
     */
    /**
     * Borra UNA versión de idioma de una plantilla en Meta.
     *
     * ### Por qué hace falta, si ya existe editar
     *
     * Porque Meta **no deja renombrar los marcadores** de una plantilla aprobada. Cambiar el
     * texto de alrededor sí; convertir `{{guest}}` en `{{huesped}}` no, porque para Meta los
     * marcadores son el contrato con la API y no palabras. El intento se rechaza con
     * *«Invalid parameter | No se puede cambiar el estado de esta plantilla de mensaje. Sólo
     * puedes eliminar o añadir plantillas»* — un mensaje que despista, porque no habla del
     * `status` sino de qué operaciones admite la plantilla.
     *
     * La salida es la que nombra el propio error: borrar esa versión y volver a crearla.
     *
     * ### ⚠️ `hsm_id` es lo que acota el borrado a UN idioma
     *
     * `DELETE {wabaId}/message_templates?name=X` borra **todos** los idiomas de esa plantilla.
     * Pasando además `hsm_id` (el id de la versión concreta) se borra sólo ése. Los dos
     * parámetros son obligatorios juntos: con `hsm_id` a secas, Meta ignora el filtro y se
     * lleva el grupo entero. Aquí `hsm_id` no es opcional por eso mismo — un borrado de más no
     * se deshace, y las versiones con tráfico se llevarían por delante sus métricas.
     *
     * @param string $templateName Nombre en Meta (`meta_template_name`).
     * @param string $hsmId        Id de la versión de idioma, el que devuelve `fetchTemplates()`.
     * @return array<string, mixed> Respuesta cruda de Meta.
     */
    public function deleteTemplateDefinition(MetaConfig $config, ExchangeEndpoint $endpoint, string $templateName, string $hsmId): array
    {
        $apiKey = $config->getCredential('apiKey') ?? $config->getApiKey();
        $wabaId = $config->getCredential('wabaId') ?? $config->getWabaId();

        if (!$apiKey || !$wabaId) {
            throw new \RuntimeException(sprintf('La configuración de Meta [%s] no tiene API Key o WABA ID para borrar.', $config->getNombre()));
        }

        // Mismo path que crear —{wabaId}/message_templates—, cambia el verbo y los filtros.
        $dynamicPath = str_replace('{wabaId}', (string) $wabaId, (string) $endpoint->getEndpoint());
        $url = sprintf('%s/%s', rtrim((string) $config->getBaseUrlRaw(), '/'), ltrim($dynamicPath, '/'));

        $response = $this->httpClient->request('DELETE', $url, [
            'headers' => ['Authorization' => 'Bearer ' . $apiKey],
            'query' => ['name' => $templateName, 'hsm_id' => $hsmId],
        ]);

        $content = $response->getContent(false);
        $decoded = json_decode($content, true);

        if ($response->getStatusCode() >= 400) {
            $baseError = $decoded['error']['message'] ?? 'Error desconocido';
            $userMsg = $decoded['error']['error_user_msg'] ?? '';

            throw new \RuntimeException('Error BORRANDO en Meta API: ' . $baseError . ($userMsg ? ' | ' . $userMsg : ''));
        }

        return $decoded ?? [];
    }

    public function editTemplateDefinition(MetaConfig $config, string $templateId, array $componentsPayload): array
    {
        $apiKey = $config->getCredential('apiKey') ?? $config->getApiKey();

        if (!$apiKey) {
            throw new \RuntimeException(sprintf('La configuración de Meta [%s] no tiene API Key.', $config->getNombre()));
        }

        // Para editar, la URL es la base + el ID numérico de la plantilla (no el path con wabaId)
        $url = sprintf('%s/%s', rtrim((string)$config->getBaseUrlRaw(), '/'), $templateId);

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
            ],
            'json' => ['components' => $componentsPayload]
        ]);

        $content = $response->getContent(false);
        $decoded = json_decode($content, true);

        if ($response->getStatusCode() >= 400) {
            $baseError = $decoded['error']['message'] ?? 'Error desconocido';
            $userMsg = $decoded['error']['error_user_msg'] ?? '';
            $details = $decoded['error']['error_data']['details'] ?? '';

            $detailedError = $baseError;
            if ($userMsg) $detailedError .= ' | ' . $userMsg;
            if ($details) $detailedError .= ' | Detalles: ' . $details;

            throw new \RuntimeException('Error EDITANDO en Meta API: ' . $detailedError);
        }

        return $decoded ?? [];
    }
}