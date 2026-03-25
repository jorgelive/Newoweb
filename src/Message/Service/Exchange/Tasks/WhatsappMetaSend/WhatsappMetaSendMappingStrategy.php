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
 *
 * * REGLAS DE SEGURIDAD INTEGRADAS:
 * Implementa Zero-Trust sobre plantillas no oficiales, bloqueando su envío fuera de 24h.
 * * ACTUALIZACIÓN: Implementa lógica concatenada (Header + Body + Footer) para mensajes libres
 * y estructuración estricta para envío oficial de plantillas.
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
     * @throws \RuntimeException Si falta la configuración crítica o se viola una política de Meta.
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
            // 🔥 ESCENARIO B: ENVÍO DE MENSAJE (Plantilla Oficial o Libre)
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
            $isOfficialMeta = $metaJson['is_official_meta'] ?? true;

            // =========================================================================
            // 🛡️ BARRERA DE SEGURIDAD (ZERO TRUST & QUICK REPLIES)
            // =========================================================================
            if (!$isSessionActive && empty($metaJson)) {
                throw new \RuntimeException(sprintf(
                    'Violación de política de Meta: Intento de envío de mensaje libre al número %s fuera de la ventana de 24 horas. El mensaje [ID: %s] requiere una plantilla oficial asociada.',
                    $destination,
                    $msg->getId()
                ));
            }

            // Si hay plantilla, PERO no es oficial (Quick Reply), bloqueamos si estamos fuera de sesión
            if (!empty($metaJson) && !$isOfficialMeta && !$isSessionActive) {
                throw new \RuntimeException(sprintf(
                    'Violación de política de Meta: Intento de enviar una plantilla NO oficial ("%s") como inicio de conversación al número %s. Solo se permiten plantillas validadas por Meta fuera de la ventana de 24 horas.',
                    $template->getCode(),
                    $destination
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

                $variables = $resolver ? $resolver->getMessageVariables($conversation->getContextId()) : [];

                // 1. PROCESAR HEADER (Variables de texto o Adjuntos)
                $headerData = $template->getWhatsappMetaHeader($lang);
                if ($headerData) {
                    $format = $headerData['format'];
                    $headerComponent = ['type' => 'header', 'parameters' => []];

                    if ($format === 'TEXT' && !empty($headerData['content'])) {
                        $headerText = $headerData['content'];
                        if (preg_match_all('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', $headerText, $matches)) {
                            foreach (array_unique($matches[1]) as $paramName) {
                                if (!isset($variables[$paramName]) || (string)$variables[$paramName] === '') {
                                    throw new \RuntimeException(sprintf('Error de seguridad (Header): La variable requerida "{{%s}}" está vacía en el contexto actual.', $paramName));
                                }
                                // NOTA ACTUALIZADA: Cuando se usan variables nombradas (ej. {{guest_name}}) en lugar de
                                // posicionales (ej. {{1}}), Meta Cloud API exige estrictamente el 'parameter_name'
                                // en todos los componentes, incluido el Header, para resolver el mapeo correctamente.
                                $headerComponent['parameters'][] = [
                                    'type' => 'text',
                                    'parameter_name' => $paramName,
                                    'text' => (string) $variables[$paramName]
                                ];
                            }
                        }
                    } elseif (in_array($format, ['IMAGE', 'VIDEO', 'DOCUMENT'])) {
                        if (!$attachment) {
                            throw new \RuntimeException(sprintf('Error de seguridad: La plantilla "%s" requiere un adjunto de tipo %s en el Header, pero no se envió ninguno.', $template->getCode(), $format));
                        }
                        $mediaType = strtolower($format);
                        $headerComponent['parameters'][] = [
                            'type' => $mediaType,
                            $mediaType => ['link' => $this->getAbsoluteAttachmentUrl($attachment)]
                        ];
                    }

                    // REGLA DE META: Solo se añade el componente si hay variables/adjuntos reales que procesar
                    if (!empty($headerComponent['parameters'])) {
                        $messagePayload['template']['components'][] = $headerComponent;
                    }
                }

                // 2. PROCESAR BODY
                $templateContent = '';
                foreach ($metaJson['body'] ?? [] as $bodyNode) {
                    if (strtolower($bodyNode['language']) === $lang) {
                        $templateContent = $bodyNode['content'] ?? '';
                        break;
                    }
                }

                $resolvedBodyParams = [];
                if ($templateContent && preg_match_all('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', $templateContent, $matches)) {
                    foreach (array_unique($matches[1]) as $paramName) {
                        if (!isset($variables[$paramName]) || (string)$variables[$paramName] === '') {
                            throw new \RuntimeException(sprintf(
                                'Error de seguridad al enviar plantilla "%s": La variable requerida "{{%s}}" está vacía o no existe en el contexto actual.',
                                $template->getCode(),
                                $paramName
                            ));
                        }

                        $resolvedBodyParams[] = [
                            'type' => 'text',
                            'parameter_name' => $paramName,
                            'text' => (string) $variables[$paramName]
                        ];
                    }
                }

                if (!empty($resolvedBodyParams)) {
                    $messagePayload['template']['components'][] = [
                        'type' => 'body',
                        'parameters' => $resolvedBodyParams
                    ];
                }

                // 3. PROCESAR FOOTER
                // (REGLA DE META: El footer JAMÁS tiene variables, por lo tanto NO SE ENVÍA como componente
                // en el payload de la plantilla oficial. Meta lo inyecta automáticamente de su lado.)

                // 4. PROCESAR BOTONES DINÁMICOS
                foreach ($metaJson['buttons_map'] ?? [] as $btn) {
                    if (($btn['type'] ?? '') === 'url') {

                        // Extraemos la llave dedicada para el resolver.
                        // El fallback str_replace se mantiene solo por compatibilidad temporal si alguna plantilla no se ha editado aún.
                        $resolverKey = $btn['resolver_key'] ?? null;
                        if (!$resolverKey) {
                            $resolverKey = str_replace(['{{', '}}', ' '], '', $btn['content'] ?? '');
                        }

                        if (!isset($variables[$resolverKey]) || (string)$variables[$resolverKey] === '') {
                            throw new \RuntimeException(sprintf(
                                'Error de seguridad al enviar plantilla "%s": La variable de botón requerida "%s" está vacía o no existe en el contexto actual.',
                                $template->getCode(),
                                $resolverKey
                            ));
                        }

                        $urlValue = (string) $variables[$resolverKey];

                        $messagePayload['template']['components'][] = [
                            'type' => 'button',
                            'sub_type' => 'url',
                            'index' => (string) ($btn['index'] ?? '0'),
                            'parameters' => [
                                ['type' => 'text', 'text' => $urlValue]
                            ]
                        ];
                    }
                }

            } else {
                // -----------------------------------------------------------------
                // ENVÍO DE MENSAJE LIBRE O QUICK REPLY INTERNO (DENTRO DE 24H)
                // -----------------------------------------------------------------

                $headerData = $template ? $template->getWhatsappMetaHeader($lang) : null;
                $footerText = $template ? $template->getWhatsappMetaFooter($lang) : null;
                $bodyText = '';

                if (!empty($metaJson['body'])) {
                    foreach ($metaJson['body'] as $bodyNode) {
                        if (strtolower($bodyNode['language']) === $lang) {
                            $bodyText = $bodyNode['content'] ?? '';
                            break;
                        }
                    }
                }

                if (empty(trim($bodyText))) {
                    $bodyText = $msg->getContentExternal() ?? $msg->getContentLocal() ?? '';
                }

                // CONCATENACIÓN VIRTUAL (Simulando la UI de Meta)
                $finalContent = "";

                // 1. Header (Solo si es de texto)
                if ($headerData && ($headerData['format'] ?? 'TEXT') === 'TEXT' && !empty($headerData['content'])) {
                    $finalContent .= "*" . trim($headerData['content']) . "*\n\n";
                }

                // 2. Body
                $finalContent .= trim($bodyText);

                // 3. Footer
                if (!empty($footerText)) {
                    $finalContent .= "\n\n_" . trim($footerText) . "_";
                }

                // Hidratamos todas las variables del bloque concatenado
                $finalContent = $this->hydrateVariables($finalContent, $resolver, $conversation->getContextId());

                // EMULAR BOTONES EN TEXTO LIBRE
                if (!empty($metaJson['buttons_map'])) {
                    $finalContent .= "\n\n";
                    $variables = $resolver ? $resolver->getMessageVariables($conversation->getContextId()) : [];

                    foreach ($metaJson['buttons_map'] as $btn) {
                        if (($btn['type'] ?? '') === 'url') {
                            $resolverKey = $btn['resolver_key'] ?? str_replace(['{{', '}}', ' '], '', $btn['content'] ?? '');
                            $urlValue = (string) ($variables[$resolverKey] ?? '');

                            $btnText = 'Enlace';
                            foreach ($btn['button_text'] ?? [] as $tr) {
                                if (strtolower($tr['language']) === $lang) {
                                    $btnText = $tr['content'];
                                    break;
                                }
                            }

                            if ($urlValue !== '') {
                                $finalContent .= "🔗 *" . $btnText . "*: " . $urlValue . "\n";
                            }
                        }
                    }
                }

                // Construcción del Payload Libre
                if ($attachment) {
                    $mediaType = $this->getWhatsappMetaMediaType($attachment);
                    $messagePayload['type'] = $mediaType;
                    $messagePayload[$mediaType] = [
                        'link' => $this->getAbsoluteAttachmentUrl($attachment)
                    ];

                    if (!empty(trim($finalContent)) && in_array($mediaType, ['image', 'video', 'document'])) {
                        $messagePayload[$mediaType]['caption'] = $finalContent;
                    }
                } else {
                    $messagePayload['type'] = 'text';
                    $messagePayload['text'] = [
                        'preview_url' => true,
                        'body' => trim($finalContent)
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