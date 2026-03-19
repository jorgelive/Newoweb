<?php

declare(strict_types=1);

namespace App\Message\Service\Exchange\Tasks\WhatsappMetaSend;

use App\Exchange\Service\Common\HomogeneousBatch;
use App\Exchange\Service\Mapping\ItemResult;
use App\Exchange\Service\Mapping\MappingResult;
use App\Exchange\Service\Mapping\MappingStrategyInterface;
use App\Message\Entity\MessageAttachment;
use App\Message\Entity\WhatsappMetaSendQueue;
use App\Message\Service\MessageDataResolverRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Vich\UploaderBundle\Storage\StorageInterface;

final readonly class WhatsappMetaSendMappingStrategy implements MappingStrategyInterface
{
    public function __construct(
        private MessageDataResolverRegistry $resolverRegistry,
        private StorageInterface $vichStorage,
        #[Autowire('%env(PANEL_HOST_URL)%')]
        private string $siteUrl
    ) {}

    public function map(HomogeneousBatch $batch): MappingResult
    {
        $config = $batch->getConfig();
        $endpoint = $batch->getEndpoint();

        $creds = $config->getCredentials();
        $sourcePhone = $creds['source_number'] ?? null;
        $appName = $creds['app_name'] ?? 'MyBedsApp';

        $fullUrl = rtrim($config->getBaseUrl(), '/') . '/' . ltrim((string)$endpoint->getEndpoint(), '/');
        $method = strtoupper((string)$endpoint->getMetodo());

        $payload = [];
        $correlation = [];

        foreach ($batch->getItems() as $index => $item) {
            /** @var WhatsappMetaSendQueue $item */
            $msg = $item->getMessage();
            $conversation = $msg->getConversation();
            $resolver = $this->resolverRegistry->getResolver($conversation->getContextType());

            $livePhone = $resolver ? $resolver->getPhoneNumber($conversation->getContextId()) : null;
            $destination = $livePhone ?: $item->getDestinationPhone();
            if ($livePhone && $livePhone !== $item->getDestinationPhone()) {
                $item->setDestinationPhone($livePhone);
            }

            $messagePayload = [
                'channel'     => 'whatsapp',
                'source'      => $sourcePhone,
                'destination' => $destination,
                'src.name'    => $appName,
            ];

            $attachment = $msg->getAttachments()->first() ?: null;
            $template = $msg->getTemplate();

            if ($template !== null) {
                $lang = $conversation->getIdioma()->getId();
                $isSessionActive = $conversation->isWhatsappSessionActive();

                if ($isSessionActive) {
                    $bodyContent = (string) $template->getWhatsappMetaBody($lang);
                    $content = $this->hydrateVariables($bodyContent, $resolver, $conversation->getContextId());

                    if ($attachment) {
                        $this->attachMediaToPayload($messagePayload, $attachment, 'free_form', $content);
                    } else {
                        $messagePayload['message'] = json_encode([
                            'type' => 'text',
                            'text' => $content
                        ], JSON_UNESCAPED_UNICODE);
                    }

                } else {
                    $templateId = $template->getWhatsappMetaTemplateId($lang);
                    $paramsMap = $template->getWhatsappMetaParamsMap();
                    $resolvedParams = [];

                    if ($resolver && !empty($paramsMap)) {
                        $variables = $resolver->getMessageVariables($conversation->getContextId());
                        foreach ($paramsMap as $paramKey) {
                            $resolvedParams[] = (string) ($variables[$paramKey] ?? '');
                        }
                    }

                    $messagePayload['template'] = json_encode([
                        'id'     => $templateId,
                        'params' => $resolvedParams
                    ], JSON_UNESCAPED_UNICODE);

                    if ($attachment) {
                        $this->attachMediaToPayload($messagePayload, $attachment, 'template');
                    }
                }
            } else {
                $content = $msg->getContentExternal() ?? $msg->getContentLocal() ?? '';
                $content = $this->hydrateVariables($content, $resolver, $conversation->getContextId());

                if ($attachment) {
                    $this->attachMediaToPayload($messagePayload, $attachment, 'free_form', $content);
                } else {
                    $messagePayload['message'] = json_encode([
                        'type' => 'text',
                        'text' => $content
                    ], JSON_UNESCAPED_UNICODE);
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

        if (isset($apiResponse['messageId']) || isset($apiResponse['status'])) {
            $apiResponse = [$apiResponse];
        }

        foreach ($apiResponse as $index => $respData) {
            if (!isset($mapping->correlationMap[$index])) {
                continue;
            }

            $queueId = $mapping->correlationMap[$index];
            $status = $respData['status'] ?? 'error';
            $success = in_array($status, ['submitted', 'queued', 'sent', 'success'], true);

            $results[$queueId] = new ItemResult(
                queueItemId: $queueId,
                success: $success,
                message: $success ? null : ($respData['message'] ?? 'Error desconocido de WhatsApp Meta'),
                remoteId: $respData['messageId'] ?? null,
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

    private function attachMediaToPayload(array &$messagePayload, MessageAttachment $attachment, string $mode, string $caption = ''): void
    {
        $mediaType = $this->getWhatsappMetaMediaType($attachment);
        $mediaUrl = $this->getAbsoluteAttachmentUrl($attachment);

        if ($mode === 'template') {
            $messagePayload['message'] = json_encode([
                'type'     => $mediaType,
                $mediaType => [
                    'link'     => $mediaUrl,
                    'filename' => $attachment->getOriginalName()
                ]
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        $mediaData = [
            'type'        => $mediaType,
            'originalUrl' => $mediaUrl,
            'previewUrl'  => $mediaUrl,
        ];

        if (!empty(trim($caption))) {
            $mediaData['caption'] = $caption;
        }

        if ($mediaType === 'file' || $mediaType === 'document') {
            $mediaData['filename'] = $attachment->getOriginalName();
            $mediaData['type'] = 'file';
        }

        $messagePayload['message'] = json_encode($mediaData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function getWhatsappMetaMediaType(MessageAttachment $attachment): string
    {
        $mime = $attachment->getMimeType() ?? '';

        if (str_starts_with($mime, 'image/')) return 'image';
        if (str_starts_with($mime, 'video/')) return 'video';
        if (str_starts_with($mime, 'audio/')) return 'audio';

        return 'file';
    }

    /**
     * Construye la URL absoluta pública del archivo usando VichUploader
     */
    private function getAbsoluteAttachmentUrl(MessageAttachment $attachment): string
    {
        $base = rtrim($this->siteUrl, '/');
        // 🔥 MAGIA: Vich devuelve el prefijo URI correcto (ej: /uploads/mi_carpeta_custom/archivo.jpg)
        $uri = $this->vichStorage->resolveUri($attachment, 'file');

        return $base . $uri;
    }
}