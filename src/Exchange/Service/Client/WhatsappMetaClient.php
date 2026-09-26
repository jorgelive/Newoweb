<?php

declare(strict_types=1);

namespace App\Exchange\Service\Client;

use App\Dto\Lee;
use App\Exchange\Dto\Meta\RespuestaGraphMeta;
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
    /**
     * Prefijo del motivo cuando NO se sabe si el mensaje salió: la petición se cortó (timeout, red)
     * o Meta contestó algo que no es su JSON (una página de error de un proxy, un 5xx vacío).
     * `WhatsappMetaSendHandler` lo lee para no reintentar: Meta no tiene clave de idempotencia, y
     * reenviar algo que quizá llegó es mandarle el mensaje dos veces al huésped. Mejor fallido y a
     * la vista del operador, que decide. Ver `docs/Mensajeria.md` §14.c.
     */
    public const string SIN_CONFIRMACION = 'Sin confirmación de Meta: ';

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
        // `MappingResult::$config` es un `ChannelConfigInterface`, y `getCredential()` NO está en
        // ese contrato: lo tiene sólo `MetaConfig`. Y está bien que sea así — `EmailConfig` y
        // `Beds24Config` no guardan credenciales por clave, así que meterlo en el contrato les
        // obligaría a fingir un método vacío.
        //
        // Lo que faltaba era comprobarlo aquí. Sin esto, un lote mal enrutado moría con
        // «Call to undefined method» y sin decir qué configuración había llegado.
        $config = $mapping->config;

        if (!$config instanceof MetaConfig) {
            throw new \RuntimeException(sprintf(
                'El cliente de Meta necesita una MetaConfig; llegó %s.',
                $config::class,
            ));
        }

        $apiKey = $config->getCredential('apiKey');

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
                $decoded = json_decode($content, true);
                $rawBodies[$index] = $decoded ?? $content;

                // La respuesta se lee una vez, por el DTO; lo que no es un objeto no trae ni error
                // ni id, y cuenta como éxito sin id, como antes.
                $respuesta = RespuestaGraphMeta::fromArray(is_array($decoded) ? $decoded : []);

                // Un cuerpo que no es el JSON de Meta —o un 5xx sin error legible— no dice si el
                // mensaje salió. Hasta el 26/09/2026 contaba como enviado sin `wamid`: un mensaje
                // perdido con cara de entregado, sin webhook de estado que lo corrigiera nunca.
                if (!$respuesta->hayError && (!is_array($decoded) || ($statusCode >= 500 && $respuesta->idMensaje === null))) {
                    $responses[$index] = [
                        'status' => 'error',
                        'message' => self::SIN_CONFIRMACION . sprintf('HTTP %d sin respuesta reconocible', $statusCode),
                    ];
                } elseif ($respuesta->hayError) {
                    $responses[$index] = [
                        'status' => 'error',
                        'message' => $respuesta->errorMensaje ?? 'Error de Meta API',
                        'error_code' => $respuesta->errorCodigo,
                    ];
                } else {
                    $responses[$index] = [
                        'status' => 'success',
                        'messageId' => $respuesta->idMensaje,
                        'raw' => $decoded
                    ];
                }
            } catch (Throwable $e) {
                $lastStatusCode = 500;
                $rawBodies[$index] = $e->getMessage();
                $responses[$index] = [
                    'status' => 'error',
                    'message' => self::SIN_CONFIRMACION . 'HTTP Exception: ' . $e->getMessage()
                ];
            }
        }

        $finalRawContent = json_encode($rawBodies, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ?: '{"_error":"la respuesta no se pudo serializar"}';

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
    /** @return array<array-key, mixed> La respuesta de Meta tal cual, ya decodificada. */
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

        $respuesta = RespuestaGraphMeta::deCuerpo($response->getContent(false));

        if ($response->getStatusCode() >= 400) {
            $errorMsg = $respuesta->errorMensaje ?? 'Error desconocido sincronizando plantillas de Meta.';
            throw new \RuntimeException('Meta API Error: ' . $errorMsg);
        }

        return $respuesta->crudo;
    }

    /**
     * PUSH DE DEFINICIÓN: Envía el JSON estructural de una plantilla para revisión de Meta.
     */
    /**
     * @param array<string, mixed> $templatePayload La plantilla con la forma que pide Meta.
     * @return array<array-key, mixed> La respuesta de Meta, ya decodificada.
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

        $respuesta = RespuestaGraphMeta::deCuerpo($response->getContent(false));

        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException(self::errorDetallado($respuesta));
        }

        return $respuesta->crudo;
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

        $respuesta = RespuestaGraphMeta::deCuerpo($response->getContent(false));

        if ($response->getStatusCode() >= 400) {
            $baseError = $respuesta->errorMensaje ?? 'Error desconocido';
            $userMsg = $respuesta->errorMensajeUsuario ?? '';

            throw new \RuntimeException('Error BORRANDO en Meta API: ' . $baseError . ($userMsg ? ' | ' . $userMsg : ''));
        }

        return Lee::objeto($respuesta->crudo);
    }

    /**
     * @param array<array-key, mixed> $componentsPayload Los componentes de la plantilla.
     * @return array<array-key, mixed> La respuesta de Meta, ya decodificada.
     */
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

        $respuesta = RespuestaGraphMeta::deCuerpo($response->getContent(false));

        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException('Error EDITANDO en Meta API: ' . self::errorDetallado($respuesta));
        }

        return $respuesta->crudo;
    }

    /**
     * 🔥 El motivo entero de un rechazo de plantilla: el mensaje técnico, el legible
     * (`error_user_msg`, que es el que dice QUÉ está mal) y los detalles. Crear y editar lo
     * componían igual, cada uno con su copia.
     */
    private static function errorDetallado(RespuestaGraphMeta $respuesta): string
    {
        $detallado = $respuesta->errorMensaje ?? 'Error desconocido';

        if ($respuesta->errorMensajeUsuario) {
            $detallado .= ' | ' . $respuesta->errorMensajeUsuario;
        }
        if ($respuesta->errorDetalles) {
            $detallado .= ' | Detalles: ' . $respuesta->errorDetalles;
        }

        return $detallado;
    }
}
