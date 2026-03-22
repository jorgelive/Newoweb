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
 */
final readonly class WhatsappMetaSendMappingStrategy implements MappingStrategyInterface
{
    public function __construct(
        private MessageDataResolverRegistry $resolverRegistry,
        private StorageInterface $vichStorage,
        #[Autowire('%env(PMS_META_PUBLIC_URL)%')]
        private string $pmsMetaPublicUrl
    ) {}

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
            // Validamos LA ACCIÓN DEL ENDPOINT como fuente principal de la verdad,
            // respaldado por la dirección y estado del mensaje.
            // El encolador los puso en Message::STATUS_QUEUED,
            // serán puestos nuevamente en Message::STATUS_READ por el persister
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

                continue; // Saltamos la lógica de envío, ya terminamos con este ítem.
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

            // Extraemos el código de idioma ISO-2 (ej. 'es', 'en') de la conversación
            $lang = $conversation->getIdioma()->getId();
            $isSessionActive = $conversation->isWhatsappSessionActive();

            if ($template !== null && !$isSessionActive) {
                // ENVÍO DE PLANTILLA OFICIAL (Fuera de la ventana de 24h)

                $templateName = $template->getWhatsappMetaName();
                if (!$templateName) {
                    throw new \RuntimeException(sprintf('La plantilla local "%s" no tiene un Nombre de Plantilla Meta configurado.', $template->getCode()));
                }

                $messagePayload['type'] = 'template';
                $messagePayload['template'] = [
                    'name' => $templateName,
                    'language' => ['code' => $lang],
                    'components' => []
                ];

                $paramsMap = $template->getWhatsappMetaParamsMap();
                $resolvedParams = [];

                if ($resolver && !empty($paramsMap)) {
                    $variables = $resolver->getMessageVariables($conversation->getContextId());

                    // Ordenamos estrictamente por meta_var (1, 2, 3...) como exige Meta
                    usort($paramsMap, fn($a, $b) => (int)($a['meta_var'] ?? 0) <=> (int)($b['meta_var'] ?? 0));

                    foreach ($paramsMap as $paramConfig) {
                        $entityField = $paramConfig['entity_field'] ?? null;

                        if ($entityField) {
                            // Extraemos el valor. Meta no acepta strings vacíos en variables, enviamos un espacio como fallback seguro.
                            $value = (string) ($variables[$entityField] ?? '');
                            $resolvedParams[] = [
                                'type' => 'text',
                                'text' => $value !== '' ? $value : ' '
                            ];
                        }
                    }
                }

                if (!empty($resolvedParams)) {
                    $messagePayload['template']['components'][] = [
                        'type' => 'body',
                        'parameters' => $resolvedParams
                    ];
                }

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
                // ENVÍO DE MENSAJE LIBRE (Dentro de la ventana de 24h)
                $content = $msg->getContentExternal() ?? $msg->getContentLocal() ?? '';
                $content = $this->hydrateVariables($content, $resolver, $conversation->getContextId());

                if ($attachment) {
                    $mediaType = $this->getWhatsappMetaMediaType($attachment);
                    $messagePayload['type'] = $mediaType;
                    $messagePayload[$mediaType] = [
                        'link' => $this->getAbsoluteAttachmentUrl($attachment)
                    ];

                    // Audio no soporta captions en la API de Meta, el resto sí.
                    if (!empty(trim($content)) && in_array($mediaType, ['image', 'video', 'document'])) {
                        $messagePayload[$mediaType]['caption'] = $content;
                    }
                } else {
                    $messagePayload['type'] = 'text';
                    $messagePayload['text'] = [
                        'preview_url' => true,
                        'body' => $content
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

    public function parseResponse(array $apiResponse, MappingResult $mapping): array
    {
        $results = [];

        // Meta devuelve 'messages' para envíos y 'success' para recibos de lectura
        if (isset($apiResponse['messages']) || isset($apiResponse['error']) || isset($apiResponse['success'])) {
            $apiResponse = [$apiResponse];
        }

        foreach ($apiResponse as $index => $respData) {
            if (!isset($mapping->correlationMap[$index])) {
                continue;
            }

            $queueId = $mapping->correlationMap[$index];
            $isError = isset($respData['error']);

            // Validamos éxito general (sin errores)
            $success = !$isError;

            $remoteId = null;
            // Solo intentamos extraer un nuevo ID si fue un envío de mensaje exitoso
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

    private function hydrateVariables(string $content, ?object $resolver, string $contextId): string
    {
        if ($resolver && str_contains($content, '{{')) {
            $variables = $resolver->getMessageVariables($contextId);
            foreach ($variables as $key => $value) {
                $content = str_replace('{{ ' . $key . ' }}', (string)$value, $content);
                $content = str_replace('{{' . $key . '}}', (string)$value, $content);
            }
        }
        return $content;
    }

    private function getWhatsappMetaMediaType(MessageAttachment $attachment): string
    {
        $mime = $attachment->getMimeType() ?? '';

        if (str_starts_with($mime, 'image/')) return 'image';
        if (str_starts_with($mime, 'video/')) return 'video';
        if (str_starts_with($mime, 'audio/')) return 'audio';

        return 'document';
    }

    /**
     * Construye la URL absoluta pública del archivo usando VichUploader
     */
    private function getAbsoluteAttachmentUrl(MessageAttachment $attachment): string
    {
        $base = rtrim($this->pmsMetaPublicUrl, '/');
        $uri = $this->vichStorage->resolveUri($attachment, 'file');

        return $base . $uri;
    }
}