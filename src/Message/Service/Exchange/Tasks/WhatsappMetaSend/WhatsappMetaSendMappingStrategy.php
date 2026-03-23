<?php

declare(strict_types=1);

namespace App\Message\Service\Exchange\Tasks\WhatsappMetaSend;

use App\Exchange\Service\Common\HomogeneousBatch;
use App\Exchange\Service\Mapping\ItemResult;
use App\Exchange\Service\Mapping\MappingResult;
use App\Exchange\Service\Mapping\MappingStrategyInterface;
use App\Message\Entity\Message;
use App\Message\Entity\MessageAttachment;
use App\Message\Entity\WhatsappMetaSendQueue;
use App\Message\Service\MessageDataResolverRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Vich\UploaderBundle\Storage\StorageInterface;

/**
 * Estrategia de mapeo unificada para WhatsApp Meta.
 * Construye el payload JSON para enviar mensajes (texto/multimedia/plantillas)
 * y también para notificar a Meta cuando un mensaje fue leído (status: read).
 * * OPTIMIZACIÓN GREENFIELD: Extrae dinámicamente las variables nombradas
 * del cuerpo usando parameter_name (soportado por Meta) y variables posicionales
 * para los botones dinámicos directamente desde el JSON.
 */
final readonly class WhatsappMetaSendMappingStrategy implements MappingStrategyInterface
{
    public function __construct(
        private MessageDataResolverRegistry $resolverRegistry,
        private StorageInterface $vichStorage,
        #[Autowire('%env(PMS_META_PUBLIC_URL)%')]
        private string $pmsMetaPublicUrl
    ) {}

    /**
     * Mapea un lote de mensajes encolados hacia la estructura esperada por la API Cloud de Meta.
     *
     * @param HomogeneousBatch $batch Lote de elementos a procesar.
     * @return MappingResult El resultado del mapeo con el payload final y el mapa de correlación.
     * @throws \RuntimeException Si falta la configuración crítica como el Phone ID.
     */
    public function map(HomogeneousBatch $batch): MappingResult
    {
        $config = $batch->getConfig();
        $endpoint = $batch->getEndpoint();

        // 1. Extraemos el Phone ID de la configuración
        $phoneId = $config->getCredential('phoneId');

        if (!$phoneId) {
            throw new \RuntimeException(sprintf('La configuración de Meta [%s] no tiene el Phone ID configurado.', $config->getNombre()));
        }

        // 2. URI Templating: Reemplazamos el comodín por el ID real
        $dynamicPath = str_replace('{phoneId}', (string)$phoneId, (string)$endpoint->getEndpoint());

        // 3. Armado de la URL final (Base + Path Dinámico)
        $fullUrl = rtrim($config->getBaseUrl(), '/') . '/' . ltrim($dynamicPath, '/');

        $method = strtoupper((string)$endpoint->getMetodo());

        $payload = [];
        $correlation = [];

        foreach ($batch->getItems() as $index => $item) {
            /** @var WhatsappMetaSendQueue $item */
            $msg = $item->getMessage();

            // =========================================================================
            // 🔥 ESCENARIO A: RECIBO DE LECTURA
            // =========================================================================
            if ($endpoint->getAccion() === 'MARK_WHATSAPP_MESSAGE_READ'
                && $msg->getDirection() === Message::DIRECTION_INCOMING
                && $msg->getStatus() === Message::STATUS_QUEUED
            ) {
                $remoteIdToRead = $msg->getWhatsappMetaExternalId();

                if ($remoteIdToRead) {
                    $payload[] = [
                        'messaging_product' => 'whatsapp',
                        'status'            => 'read',
                        'message_id'        => $remoteIdToRead,
                    ];
                    $correlation[$index] = (string) $item->getId();
                }

                continue;
            }

            // =========================================================================
            // 🔥 ESCENARIO B: ENVÍO DE MENSAJE (Plantilla o Libre)
            // =========================================================================

            $conversation = $msg->getConversation();
            $resolver = $this->resolverRegistry->getResolver($conversation->getContextType());

            $livePhone = $resolver ? $resolver->getPhoneNumber($conversation->getContextId()) : null;
            $destination = $livePhone ?: $item->getDestinationPhone();

            if ($livePhone && $livePhone !== $item->getDestinationPhone()) {
                $item->setDestinationPhone($livePhone);
            }

            // ESTRUCTURA BASE NATIVA DE META CLOUD API
            $messagePayload = [
                'messaging_product' => 'whatsapp',
                'recipient_type'    => 'individual',
                'to'                => $destination,
            ];

            $attachment = $msg->getAttachments()->first() ?: null;
            $template = $msg->getTemplate();
            $lang = strtolower($conversation->getIdioma()->getId());
            $isSessionActive = $conversation->isWhatsappSessionActive();

            // Obtenemos el nuevo JSON Greenfield completo
            $metaJson = $template !== null ? $template->getWhatsappMetaTmpl() : [];

            // =========================================================================
            // 🛡️ BARRERA DE SEGURIDAD (ZERO TRUST)
            // =========================================================================
            if (!$isSessionActive && empty($metaJson)) {
                throw new \RuntimeException(sprintf(
                    'Violación de política de Meta: Intento de envío de mensaje libre al número %s fuera de la ventana de 24 horas. El mensaje [ID: %s] requiere una plantilla oficial asociada.',
                    $destination,
                    $msg->getId()
                ));
            }

            if (!empty($metaJson) && !$isSessionActive) {
                // -----------------------------------------------------------------
                // ENVÍO DE PLANTILLA OFICIAL (FUERA DE 24H)
                // -----------------------------------------------------------------
                $templateName = $metaJson['meta_template_name'] ?? null;

                if (!$templateName) {
                    throw new \RuntimeException(sprintf('La plantilla local "%s" no tiene un Nombre de Plantilla Meta configurado.', $template->getCode()));
                }

                $messagePayload['type'] = 'template';
                $messagePayload['template'] = [
                    'name' => $templateName,
                    'language' => ['code' => $lang],
                    'components' => []
                ];

                // 1. BUSCAR EL TEXTO EN EL IDIOMA CORRECTO DENTRO DEL BODY
                $templateContent = '';
                foreach ($metaJson['body'] ?? [] as $bodyNode) {
                    if (strtolower($bodyNode['language']) === $lang) {
                        $templateContent = $bodyNode['content'] ?? '';
                        break;
                    }
                }

                $variables = $resolver ? $resolver->getMessageVariables($conversation->getContextId()) : [];

                // 2. EXTRAER VARIABLES DEL CUERPO (USANDO PARAMETER_NAME)
                $resolvedBodyParams = [];
                if ($templateContent && preg_match_all('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', $templateContent, $matches)) {
                    // Meta acepta variables nombradas en el body, usamos array_unique para no duplicar el parameter_name
                    $uniqueParamNames = array_unique($matches[1]);

                    foreach ($uniqueParamNames as $paramName) {
                        $value = (string) ($variables[$paramName] ?? '');
                        $resolvedBodyParams[] = [
                            'type' => 'text',
                            'parameter_name' => $paramName,
                            'text' => $value !== '' ? $value : ' ' // Meta requiere un espacio si está vacío
                        ];
                    }
                }

                if (!empty($resolvedBodyParams)) {
                    $messagePayload['template']['components'][] = [
                        'type' => 'body',
                        'parameters' => $resolvedBodyParams
                    ];
                }

                // 3. PROCESAR BOTONES DINÁMICOS (POSICIONAL ESTRICTO)
                foreach ($metaJson['buttons_map'] ?? [] as $btn) {
                    if (($btn['type'] ?? '') === 'url') {
                        // Limpiamos la variable (ej: "{{url_checkin}}" -> "url_checkin")
                        $varName = str_replace(['{{', '}}', ' '], '', $btn['content'] ?? '');
                        $urlValue = (string) ($variables[$varName] ?? '');

                        if ($urlValue !== '') {
                            $messagePayload['template']['components'][] = [
                                'type' => 'button',
                                'sub_type' => 'url',
                                'index' => (string) ($btn['index'] ?? '0'),
                                'parameters' => [
                                    // Los botones en Meta NO aceptan parameter_name, solo el type y text
                                    ['type' => 'text', 'text' => $urlValue]
                                ]
                            ];
                        }
                    }
                }

                // 4. PROCESAR ADJUNTOS EN EL HEADER
                if ($attachment) {
                    $mediaType = $this->getWhatsappMetaMediaType($attachment);
                    $messagePayload['template']['components'][] = [
                        'type' => 'header',
                        'parameters' => [
                            [
                                'type' => $mediaType,
                                $mediaType => [
                                    'link' => $this->getAbsoluteAttachmentUrl($attachment)
                                ]
                            ]
                        ]
                    ];
                }

            } else {
                // -----------------------------------------------------------------
                // ENVÍO DE MENSAJE LIBRE (DENTRO DE 24H)
                // -----------------------------------------------------------------

                // 1. Extraemos el contenido. Priorizamos la plantilla si existe
                $content = '';
                if (!empty($metaJson['body'])) {
                    foreach ($metaJson['body'] as $bodyNode) {
                        if (strtolower($bodyNode['language']) === $lang) {
                            $content = $bodyNode['content'] ?? '';
                            break;
                        }
                    }
                }

                // 2. Si no había plantilla, usamos el texto libre tipeado por el usuario
                if (empty(trim($content))) {
                    $content = $msg->getContentExternal() ?? $msg->getContentLocal() ?? '';
                }

                // 3. Hidratamos las variables (Soporta {{ guest_name }})
                $content = $this->hydrateVariables($content, $resolver, $conversation->getContextId());

                // 4. EMULAR BOTONES EN TEXTO LIBRE
                // Como es texto libre, los botones UI de Meta no existen. Los anexamos como links de texto.
                if (!empty($metaJson['buttons_map'])) {
                    $content .= "\n\n";
                    $variables = $resolver ? $resolver->getMessageVariables($conversation->getContextId()) : [];

                    foreach ($metaJson['buttons_map'] as $btn) {
                        if (($btn['type'] ?? '') === 'url') {
                            $varName = str_replace(['{{', '}}', ' '], '', $btn['content'] ?? '');
                            $urlValue = (string) ($variables[$varName] ?? '');

                            // Buscar la traducción de la etiqueta del botón
                            $btnText = 'Enlace';
                            foreach ($btn['button_text'] ?? [] as $tr) {
                                if (strtolower($tr['language']) === $lang) {
                                    $btnText = $tr['content'];
                                    break;
                                }
                            }

                            if ($urlValue !== '') {
                                $content .= "🔗 *" . $btnText . "*: " . $urlValue . "\n";
                            }
                        }
                    }
                }

                if ($attachment) {
                    $mediaType = $this->getWhatsappMetaMediaType($attachment);
                    $messagePayload['type'] = $mediaType;
                    $messagePayload[$mediaType] = [
                        'link' => $this->getAbsoluteAttachmentUrl($attachment)
                    ];

                    if (!empty(trim($content)) && in_array($mediaType, ['image', 'video', 'document'])) {
                        $messagePayload[$mediaType]['caption'] = $content;
                    }
                } else {
                    $messagePayload['type'] = 'text';
                    $messagePayload['text'] = [
                        'preview_url' => true,
                        'body' => trim($content)
                    ];
                }
            }

            $payload[] = $messagePayload;
            $correlation[$index] = (string) $item->getId();
        }

        return new MappingResult(
            method: $method,
            fullUrl: $fullUrl,
            payload: $payload,
            config: $config,
            correlationMap: $correlation
        );
    }

    /**
     * Analiza la respuesta de la API Cloud de Meta para determinar el éxito del envío o lectura.
     */
    public function parseResponse(array $apiResponse, MappingResult $mapping): array
    {
        $results = [];

        if (isset($apiResponse['messages']) || isset($apiResponse['error']) || isset($apiResponse['success'])) {
            $apiResponse = [$apiResponse];
        }

        foreach ($apiResponse as $index => $respData) {
            if (!isset($mapping->correlationMap[$index])) {
                continue;
            }

            $queueId = $mapping->correlationMap[$index];
            $isError = isset($respData['error']);

            $success = !$isError;
            $remoteId = null;

            if ($success && isset($respData['messages'][0]['id'])) {
                $remoteId = $respData['messages'][0]['id'];
            }

            $errorMessage = $isError ? ($respData['error']['message'] ?? 'Error desconocido de Meta') : null;

            $results[$queueId] = new ItemResult(
                queueItemId: $queueId,
                success: $success,
                message: $errorMessage,
                remoteId: $remoteId,
                extraData: (array)$respData
            );
        }

        return $results;
    }

    /**
     * Interpola variables dinámicas en el texto libre usando Regex.
     */
    private function hydrateVariables(string $content, ?object $resolver, string $contextId): string
    {
        if ($resolver && str_contains($content, '{{')) {
            $variables = $resolver->getMessageVariables($contextId);

            $content = preg_replace_callback(
                '/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/',
                function (array $matches) use ($variables): string {
                    $key = $matches[1];
                    return (string) ($variables[$key] ?? $matches[0]);
                },
                $content
            );
        }
        return $content;
    }

    /**
     * Determina el tipo de medio nativo de Meta.
     */
    private function getWhatsappMetaMediaType(MessageAttachment $attachment): string
    {
        $mime = $attachment->getMimeType() ?? '';

        if (str_starts_with($mime, 'image/')) return 'image';
        if (str_starts_with($mime, 'video/')) return 'video';
        if (str_starts_with($mime, 'audio/')) return 'audio';

        return 'document';
    }

    /**
     * Construye la URL absoluta pública del archivo.
     */
    private function getAbsoluteAttachmentUrl(MessageAttachment $attachment): string
    {
        $base = rtrim($this->pmsMetaPublicUrl, '/');
        $uri = $this->vichStorage->resolveUri($attachment, 'file');

        return $base . $uri;
    }
}