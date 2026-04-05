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
use RuntimeException;
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
     */
    public function map(HomogeneousBatch $batch): MappingResult
    {
        $config = $batch->getConfig();
        $endpoint = $batch->getEndpoint();

        // 1. Extraemos el Phone ID de la configuración
        $phoneId = $config->getCredential('phoneId');

        if (!$phoneId) {
            throw new RuntimeException(sprintf('La configuración de Meta [%s] no tiene el Phone ID configurado.', $config->getNombre()));
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

            // 🔥 ESCENARIO A: RECIBO DE LECTURA
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

            // 🔥 ESCENARIO B: ENVÍO DE MENSAJE
            $conversation = $msg->getConversation();
            $resolver = $this->resolverRegistry->getResolver($conversation->getContextType());

            $livePhone = $resolver?->getPhoneNumber($conversation->getContextId());
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
            $idiomaEntity = $conversation->getIdioma();
            $internalLang = strtolower($idiomaEntity->getId());
            // NUEVO: Bifurcación. Si la prioridad es 0, las plantillas y menús caen en inglés.
            $templateLang = ($idiomaEntity->getPrioridad() > 0) ? $internalLang : 'en';

            $metaLang = $this->normalizeLanguageForMeta($templateLang);

            $isSessionActive = $conversation->isWhatsappSessionActive();

            // Obtenemos el nuevo JSON Greenfield completo
            $metaJson = $template !== null ? $template->getWhatsappMetaTmpl() : [];
            $isOfficialMeta = $metaJson['is_official_meta'] ?? true;

            // 🛡️ BARRERA DE SEGURIDAD
            if (!$isSessionActive && empty($metaJson)) {
                throw new RuntimeException(sprintf('Violación de política: Intento de envío libre fuera de ventana a %s.', $destination));
            }

            // Si hay plantilla, PERO no es oficial (Quick Reply), bloqueamos si estamos fuera de sesión
            if (!empty($metaJson) && !$isOfficialMeta && !$isSessionActive) {
                throw new RuntimeException(sprintf('Violación de política: Plantilla NO oficial "%s" fuera de ventana.', $template->getCode()));
            }

            if (!empty($metaJson) && !$isSessionActive) {
                // -----------------------------------------------------------------
                // ENVÍO DE PLANTILLA OFICIAL (FUERA DE 24H)
                // -----------------------------------------------------------------
                $templateName = $metaJson['meta_template_name'] ?? null;

                if (!$templateName) {
                    throw new RuntimeException(sprintf('Plantilla "%s" sin Nombre Meta.', $template->getCode()));
                }

                $messagePayload['type'] = 'template';
                $messagePayload['template'] = [
                    'name' => $templateName,
                    'language' => ['code' => $metaLang],
                    'components' => []
                ];

                $variables = $resolver ? $resolver->getMessageVariables($conversation->getContextId()) : [];

                // 1. HEADER
                $headerData = $template->getWhatsappMetaHeader($templateLang);
                if ($headerData) {
                    $format = $headerData['format'];
                    $headerComponent = ['type' => 'header', 'parameters' => []];

                    if ($format === 'TEXT' && !empty($headerData['content'])) {
                        $headerText = $headerData['content'];
                        if (preg_match_all('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', $headerText, $matches)) {
                            foreach (array_unique($matches[1]) as $paramName) {
                                if (!isset($variables[$paramName]) || (string)$variables[$paramName] === '') {
                                    throw new RuntimeException(sprintf('Error (Header): Variable "%s" vacía.', $paramName));
                                }
                                $headerComponent['parameters'][] = [
                                    'type' => 'text',
                                    'parameter_name' => $paramName,
                                    'text' => (string) ($variables[$paramName] ?? '')
                                ];
                            }
                        }
                    } elseif (in_array($format, ['IMAGE', 'VIDEO', 'DOCUMENT'])) {
                        if (!$attachment) {
                            throw new RuntimeException(sprintf('La plantilla "%s" requiere adjunto %s en el Header.', $template->getCode(), $format));
                        }
                        $mediaType = strtolower($format);
                        $headerComponent['parameters'][] = [
                            'type' => $mediaType,
                            $mediaType => ['link' => $this->getAbsoluteAttachmentUrl($attachment)]
                        ];
                    }
                    if (!empty($headerComponent['parameters'])) $messagePayload['template']['components'][] = $headerComponent;
                }

                // 2. BODY
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
                            throw new RuntimeException(sprintf('Error (Body): Variable "%s" vacía en plantilla "%s".', $paramName, $template->getCode()));
                        }

                        $resolvedBodyParams[] = [
                            'type' => 'text',
                            'parameter_name' => $paramName,
                            'text' => (string) ($variables[$paramName] ?? '')
                        ];
                    }
                }

                if (!empty($resolvedBodyParams)) {
                    $messagePayload['template']['components'][] = ['type' => 'body', 'parameters' => $resolvedBodyParams];
                }

                // 3. BOTONES NATIVOS (Modificado para soportar URL y QUICK_REPLY)
                foreach ($metaJson['buttons_map'] ?? [] as $btn) {
                    $btnType = strtolower((string)($btn['type'] ?? ''));
                    $indexStr = (string) ($btn['index'] ?? '0');

                    if ($btnType === 'url') {
                        $resolverKey = $btn['resolver_key'] ?? str_replace(['{{', '}}', ' '], '', $btn['content'] ?? '');
                        $urlValue = (string) ($variables[$resolverKey] ?? '');

                        $messagePayload['template']['components'][] = [
                            'type' => 'button',
                            'sub_type' => 'url',
                            'index' => $indexStr,
                            'parameters' => [
                                ['type' => 'text', 'text' => $urlValue]
                            ]
                        ];
                    } elseif ($btnType === 'quick_reply') {
                        // 🔥 INYECCIÓN DE PAYLOAD: Aquí mandamos tu CMD_ a Meta en el envío
                        $resolverKey = (string)($btn['resolver_key'] ?? '');

                        if ($resolverKey === '') {
                            throw new RuntimeException(sprintf('Error (Botones): La plantilla "%s" intenta enviar un Quick Reply sin "resolver_key".', $template->getCode()));
                        }

                        $messagePayload['template']['components'][] = [
                            'type' => 'button',
                            'sub_type' => 'quick_reply',
                            'index' => $indexStr,
                            'parameters' => [
                                ['type' => 'payload', 'payload' => $resolverKey]
                            ]
                        ];
                    }
                }

            } else {
                // -----------------------------------------------------------------
                // ENVÍO DE MENSAJE LIBRE O QUICK REPLY INTERNO (DENTRO DE 24H)
                // -----------------------------------------------------------------

                // Usamos templateLang para consultar las plantillas locales de Doctrine
                $headerData = $template?->getWhatsappMetaHeader($templateLang);
                $footerText = $template?->getWhatsappMetaFooter($templateLang);
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

                // 🎯 EMULAR BOTONES DINÁMICOS EN TEXTO LIBRE (UX MEJORADO)
                if (!empty($metaJson['buttons_map'])) {

                    // 1. Detección Inteligente: ¿Hay opciones para interactuar o son puros links?
                    $hasQuickReplies = false;
                    foreach ($metaJson['buttons_map'] as $btn) {
                        if (strtolower($btn['type'] ?? '') === 'quick_reply') {
                            $hasQuickReplies = true;
                            break;
                        }
                    }

                    // 2. Obtenemos las traducciones del menú adaptado
                    $menuTexts = $this->getMenuTranslations($templateLang);

                    // 3. Inyectamos el título semánticamente correcto
                    $headerTitle = $hasQuickReplies ? $menuTexts['header_options'] : $menuTexts['header_links'];
                    $finalContent .= "\n\n" . $headerTitle . "\n\n";

                    $variables = $resolver ? $resolver->getMessageVariables($conversation->getContextId()) : [];
                    $quickReplyIndex = 1;

                    foreach ($metaJson['buttons_map'] as $btn) {
                        $btnType = strtolower($btn['type'] ?? '');

                        // 3. Usamos el botón dinámico con fallback traducido
                        $btnText = $menuTexts['default_btn'];

                        foreach ($btn['button_text'] ?? [] as $tr) {
                            if ($this->normalizeLanguageForMeta(strtolower($tr['language'])) === $metaLang) {
                                $btnText = $tr['content'];
                                break;
                            }
                        }

                        if ($btnType === 'url') {
                            $resolverKey = $btn['resolver_key'] ?? str_replace(['{{', '}}', ' '], '', $btn['content'] ?? '');
                            $fallbackKey = str_ends_with($resolverKey, '_path') ? str_replace('_path', '_url', $resolverKey) : $resolverKey;
                            $urlValue = (string) ($variables[$fallbackKey] ?? $variables[$resolverKey] ?? '');

                            if ($urlValue !== '') {
                                $finalContent .= "🔗 *" . trim($btnText) . "*:\n" . $urlValue . "\n\n";
                            }
                        } elseif ($btnType === 'quick_reply') {
                            $finalContent .= $quickReplyIndex . "️⃣ *" . trim($btnText) . "*\n\n";
                            $quickReplyIndex++;
                        }
                    }

                    // 4. Inyectamos el footer dinámico SOLO si hay Quick Replies reales
                    if ($hasQuickReplies) {
                        $finalContent = rtrim($finalContent) . "\n\n" . $menuTexts['footer'];
                    } else {
                        $finalContent = rtrim($finalContent);
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
        $map = ['pt' => 'pt_BR'];
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

    /**
     * Diccionario rápido para los textos de la interfaz emulada.
     * Soporta dos contextos visuales:
     * - header_options: Para menús mixtos o interactivos.
     * - header_links: Para menús que solo contienen URLs (sin interacción del usuario).
     */
    private function getMenuTranslations(string $lang): array
    {
        $translations = [
            'es' => [
                'header_options' => '*— Opciones —*',
                'header_links'   => '*— Enlaces —*',
                'footer'         => '_👉 Responde con el número de tu opción._',
                'default_btn'    => 'Opción'
            ],
            'en' => [
                'header_options' => '*— Options —*',
                'header_links'   => '*— Links —*',
                'footer'         => '_👉 Reply with the number of your option._',
                'default_btn'    => 'Option'
            ],
            'pt' => [
                'header_options' => '*— Opções —*',
                'header_links'   => '*— Links —*',
                'footer'         => '_👉 Responda com o número da sua opção._',
                'default_btn'    => 'Opção'
            ],
            'fr' => [
                'header_options' => '*— Options —*',
                'header_links'   => '*— Liens —*',
                'footer'         => '_👉 Répondez avec le numéro de votre option._',
                'default_btn'    => 'Option'
            ],
            'it' => [
                'header_options' => '*— Opzioni —*',
                'header_links'   => '*— Link —*',
                'footer'         => '_👉 Rispondi con il numero della tua opzione._',
                'default_btn'    => 'Opzione'
            ],
            'de' => [
                'header_options' => '*— Optionen —*',
                'header_links'   => '*— Links —*',
                'footer'         => '_👉 Antworten Sie mit der Nummer Ihrer Option._',
                'default_btn'    => 'Option'
            ],
            'nl' => [
                'header_options' => '*— Opties —*',
                'header_links'   => '*— Links —*',
                'footer'         => '_👉 Antwoord met het nummer van uw optie._',
                'default_btn'    => 'Optie'
            ]
        ];

        // Retorna el idioma solicitado o hace fallback a inglés
        return $translations[$lang] ?? $translations['en'];
    }
}