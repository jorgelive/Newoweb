<?php

declare(strict_types=1);

namespace App\Message\Service\Exchange\Tasks\WhatsappGupshupSend;

use App\Exchange\Service\Common\HomogeneousBatch;
use App\Exchange\Service\Mapping\ItemResult;
use App\Exchange\Service\Mapping\MappingResult;
use App\Exchange\Service\Mapping\MappingStrategyInterface;
use App\Message\Entity\MessageAttachment;
use App\Message\Entity\WhatsappGupshupSendQueue;
use App\Message\Service\MessageDataResolverRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Vich\UploaderBundle\Storage\StorageInterface;

final readonly class WhatsappGupshupSendMappingStrategy implements MappingStrategyInterface
{
    public function __construct(
        private MessageDataResolverRegistry $resolverRegistry,
        private StorageInterface $vichStorage, // 🔥 Inyectamos Vich
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
            /** @var WhatsappGupshupSendQueue $item */
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
            $content = $msg->getContentExternal() ?? $msg->getContentLocal() ?? '';

            if ($template = $msg->getTemplate()) {
                $lang = $conversation->getIdioma()->getId();
                $templateId = $template->getWhatsappGupshupTemplateId($lang);

                $paramsMap = $template->getWhatsappGupshupParamsMap();
                $resolvedParams = [];

                if ($resolver && !empty($paramsMap)) {
                    $variables = $resolver->getTemplateVariables($conversation->getContextId());
                    foreach ($paramsMap as $paramKey) {
                        $resolvedParams[] = (string) ($variables[$paramKey] ?? '');
                    }
                }

                $messagePayload['template'] = json_encode([
                    'id'     => $templateId,
                    'params' => $resolvedParams
                ], JSON_UNESCAPED_UNICODE);

                if ($attachment) {
                    $mediaType = $this->getGupshupMediaType($attachment);
                    $mediaUrl = $this->getAbsoluteAttachmentUrl($attachment);

                    $messagePayload['message'] = json_encode([
                        'type'     => $mediaType,
                        $mediaType => [
                            'link'     => $mediaUrl,
                            'filename' => $attachment->getOriginalName()
                        ]
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }

            } else {
                if ($resolver && str_contains($content, '{{')) {
                    $variables = $resolver->getTemplateVariables($conversation->getContextId());
                    foreach ($variables as $key => $value) {
                        $content = str_replace('{{ ' . $key . ' }}', (string)$value, $content);
                        $content = str_replace('{{' . $key . '}}', (string)$value, $content);
                    }
                }

                if ($attachment) {
                    $mediaType = $this->getGupshupMediaType($attachment);
                    $mediaUrl = $this->getAbsoluteAttachmentUrl($attachment);

                    $mediaData = [
                        'type'        => $mediaType,
                        'originalUrl' => $mediaUrl,
                        'previewUrl'  => $mediaUrl,
                    ];

                    if (!empty(trim($content))) {
                        $mediaData['caption'] = $content;
                    }

                    if ($mediaType === 'file' || $mediaType === 'document') {
                        $mediaData['filename'] = $attachment->getOriginalName();
                        $mediaData['type'] = 'file';
                    }

                    $messagePayload['message'] = json_encode($mediaData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

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
            if (!isset($mapping->correlationMap[$index])) continue;

            $queueId = $mapping->correlationMap[$index];
            $status = $respData['status'] ?? 'error';
            $success = in_array($status, ['submitted', 'queued', 'sent', 'success']);

            $results[$queueId] = new ItemResult(
                queueItemId: $queueId,
                success: $success,
                message: $success ? null : ($respData['message'] ?? 'Error Gupshup'),
                remoteId: $respData['messageId'] ?? null,
                extraData: (array)$respData
            );
        }

        return $results;
    }

    // =========================================================================
    // HELPERS PARA ARCHIVOS ADJUNTOS
    // =========================================================================

    private function getGupshupMediaType(MessageAttachment $attachment): string
    {
        $mime = $attachment->getMimeType() ?? '';

        if (str_starts_with($mime, 'image/')) return 'image';
        if (str_starts_with($mime, 'video/')) return 'video';
        if (str_starts_with($mime, 'audio/')) return 'audio';

        return 'file'; // PDF, DOCX, etc.
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