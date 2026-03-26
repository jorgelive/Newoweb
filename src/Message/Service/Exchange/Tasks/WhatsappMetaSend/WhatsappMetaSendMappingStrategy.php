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
 *
 * * ACTUALIZACIÓN OMNICANAL Y CONVENCIONES:
 * 1. Idiomas: Normaliza códigos genéricos ('pt' -> 'pt_BR') para evitar rechazos de la API.
 * 2. Enrutamiento: Adopta el estándar de sufijos (_path para botones nativos, _url para texto libre).
 * 3. Fallbacks: Emula botones en texto libre resolviendo dinámicamente las URLs absolutas.
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
            // 🔥 ESCENARIO A: RECIBO DE LECTURA (Mark as Read)
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
            // 🔥 ESCENARIO B: ENVÍO DE MENSAJE (Plantillas Oficiales o Libre)
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

            // -------------------------------------------------------------------------
            // 🌐 RESOLUCIÓN DE IDIOMAS (Local vs Meta API)
            // -------------------------------------------------------------------------
            $internalLang = strtolower($conversation->getIdioma()->getId());
            $metaLang = $this->normalizeLanguageForMeta($internalLang);

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
                    'language' => ['code' => $metaLang], // Inyección del código homologado para Meta
                    'components' => []
                ];

                $variables = $resolver ? $resolver->getMessageVariables($conversation->getContextId()) : [];

                // 1. PROCESAR HEADER
                $headerData = $template->getWhatsappMetaHeader($internalLang);
                if ($headerData) {
                    $format = $headerData['format'];
                    $headerComponent = ['type' => 'header', 'parameters' => []];

                    if ($format === 'TEXT' && !empty($headerData['content'])) {
                        $headerText = $headerData['content'];
                        if (preg_match_all('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', $headerText, $matches)) {
                            foreach (array_unique($matches[1]) as $paramName) {
                                if (!isset($variables[$paramName]) || (string)$variables[$paramName] === '') {
                                    throw new \RuntimeException(sprintf('Error (Header): Variable "%s" vacía.', $paramName));
                                }
                                // Meta exige 'parameter_name' en todos los componentes cuando se usan variables nombradas
                                $headerComponent['parameters'][] = [
                                    'type' => 'text',
                                    'parameter_name' => $paramName,
                                    'text' => (string) $variables[$paramName]
                                ];
                            }
                        }
                    } elseif (in_array($format, ['IMAGE', 'VIDEO', 'DOCUMENT'])) {
                        if (!$attachment) {
                            throw new \RuntimeException(sprintf('La plantilla "%s" requiere adjunto %s en el Header.', $template->getCode(), $format));
                        }
                        $mediaType = strtolower($format);
                        $headerComponent['parameters'][] = [
                            'type' => $mediaType,
                            $mediaType => ['link' => $this->getAbsoluteAttachmentUrl($attachment)]
                        ];
                    }

                    if (!empty($headerComponent['parameters'])) {
                        $messagePayload['template']['components'][] = $headerComponent;
                    }
                }

                // 2. PROCESAR BODY (Búsqueda normalizada pt vs pt_BR)
                $templateContent = '';
                foreach ($metaJson['body'] ?? [] as $bodyNode) {
                    if ($this->normalizeLanguageForMeta(strtolower($bodyNode['language'])) === $metaLang) {
                        $templateContent = $bodyNode['content'] ?? '';
                        break;
                    }
                }

                $resolvedBodyParams = [];
                if ($templateContent && preg_match_all('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', $templateContent, $matches)) {
                    foreach (array_unique($matches[1]) as $paramName) {
                        if (!isset($variables[$paramName]) || (string)$variables[$paramName] === '') {
                            throw new \RuntimeException(sprintf('Error (Body): Variable "%s" vacía en plantilla "%s".', $paramName, $template->getCode()));
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

                // 3. PROCESAR BOTONES DINÁMICOS
                foreach ($metaJson['buttons_map'] ?? [] as $btn) {
                    if (($btn['type'] ?? '') === 'url') {
                        $resolverKey = $btn['resolver_key'] ?? str_replace(['{{', '}}', ' '], '', $btn['content'] ?? '');

                        if (!isset($variables[$resolverKey]) || (string)$variables[$resolverKey] === '') {
                            throw new \RuntimeException(sprintf('Error (Botón): La variable de enlace "%s" está vacía.', $resolverKey));
                        }

                        // Al ser plantilla oficial de Meta, enviamos exactamente lo que el resolver mandó
                        // (Esperamos que sea el _path relativo según nuestra convención)
                        $urlValue = (string) $variables[$resolverKey];

                        $messagePayload['template']['components'][] = [
                            'type' => 'button',
                            'sub_type' => 'url',
                            'index' => (string) ($btn['index'] ?? '0'),
                            'parameters' => [['type' => 'text', 'text' => $urlValue]]
                        ];
                    }
                }

            } else {
                // -----------------------------------------------------------------
                // ENVÍO DE MENSAJE LIBRE O QUICK REPLY INTERNO (DENTRO DE 24H)
                // -----------------------------------------------------------------

                // Usamos internalLang para consultar las entidades locales de Doctrine
                $headerData = $template ? $template->getWhatsappMetaHeader($internalLang) : null;
                $footerText = $template ? $template->getWhatsappMetaFooter($internalLang) : null;
                $bodyText = '';

                if (!empty($metaJson['body'])) {
                    foreach ($metaJson['body'] as $bodyNode) {
                        if ($this->normalizeLanguageForMeta(strtolower($bodyNode['language'])) === $metaLang) {
                            $bodyText = $bodyNode['content'] ?? '';
                            break;
                        }
                    }
                }

                if (empty(trim($bodyText))) {
                    $bodyText = $msg->getContentExternal() ?? $msg->getContentLocal() ?? '';
                }

                // CONCATENACIÓN VIRTUAL (Simulando visualmente la UI de Meta)
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

                // Hidratamos todas las variables del bloque de texto principal
                $finalContent = $this->hydrateVariables($finalContent, $resolver, $conversation->getContextId());

                // EMULAR BOTONES DINÁMICOS EN TEXTO LIBRE
                if (!empty($metaJson['buttons_map'])) {
                    $finalContent .= "\n\n";
                    $variables = $resolver ? $resolver->getMessageVariables($conversation->getContextId()) : [];

                    foreach ($metaJson['buttons_map'] as $btn) {
                        if (($btn['type'] ?? '') === 'url') {
                            $resolverKey = $btn['resolver_key'] ?? str_replace(['{{', '}}', ' '], '', $btn['content'] ?? '');

                            // CONVENCIÓN DE ENRUTAMIENTO (Path vs URL)
                            // Si la plantilla oficial usa un "_path" para el botón nativo, al emularlo en texto libre
                            // intentamos buscar su contraparte "_url" absoluta para asegurar que el enlace sea clickeable.
                            $fallbackKey = str_ends_with($resolverKey, '_path')
                                ? str_replace('_path', '_url', $resolverKey)
                                : $resolverKey;

                            $urlValue = (string) ($variables[$fallbackKey] ?? $variables[$resolverKey] ?? '');

                            $btnText = 'Enlace';
                            foreach ($btn['button_text'] ?? [] as $tr) {
                                if ($this->normalizeLanguageForMeta(strtolower($tr['language'])) === $metaLang) {
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

                // Construcción del Payload de Texto Libre o Multimedia
                if ($attachment) {
                    $mediaType = $this->getWhatsappMetaMediaType($attachment);
                    $messagePayload['type'] = $mediaType;
                    $messagePayload[$mediaType] = ['link' => $this->getAbsoluteAttachmentUrl($attachment)];

                    if (!empty(trim($finalContent)) && in_array($mediaType, ['image', 'video', 'document'])) {
                        $messagePayload[$mediaType]['caption'] = $finalContent;
                    }
                } else {
                    $messagePayload['type'] = 'text';
                    $messagePayload['text'] = ['preview_url' => true, 'body' => trim($finalContent)];
                }
            }

            $payload[] = $messagePayload;
            $correlation[$index] = (string) $item->getId();
        }

        return new MappingResult($method, $fullUrl, $payload, $config, $correlation);
    }

    /**
     * Normaliza el código de idioma interno hacia los códigos estrictos de la API de Meta.
     * Mapea genéricos como 'pt' hacia la opción regional 'pt_BR' exigida por Meta Cloud.
     *
     * @param string $lang Código de idioma en minúsculas (ej. 'pt', 'es').
     * @return string Código homologado para Meta.
     */
    private function normalizeLanguageForMeta(string $lang): string
    {
        $map = [
            'pt' => 'pt_BR',
        ];

        return $map[$lang] ?? $lang;
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
            if (!isset($mapping->correlationMap[$index])) continue;

            $queueId = $mapping->correlationMap[$index];
            $isError = isset($respData['error']);
            $success = !$isError;

            // Meta devuelve el identificador 'wamid...' en el nodo messages[0][id]
            $remoteId = $success && isset($respData['messages'][0]['id']) ? $respData['messages'][0]['id'] : null;

            $results[$queueId] = new ItemResult(
                queueItemId: $queueId,
                success: $success,
                message: $isError ? ($respData['error']['message'] ?? 'Error desconocido de Meta') : null,
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
            return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', fn($m) => (string)($variables[$m[1]] ?? $m[0]), $content);
        }
        return $content;
    }

    /**
     * Determina el tipo de medio nativo de Meta basado en el MimeType del archivo.
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
     * Construye la URL absoluta pública del archivo para que el CDN de Meta pueda descargarlo.
     */
    private function getAbsoluteAttachmentUrl(MessageAttachment $attachment): string
    {
        return rtrim($this->pmsMetaPublicUrl, '/') . $this->vichStorage->resolveUri($attachment, 'file');
    }
}