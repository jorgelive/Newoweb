<?php

declare(strict_types=1);

namespace App\Message\Service\Exchange\Tasks\Beds24Send;

use App\Exchange\Service\Common\HomogeneousBatch;
use App\Exchange\Service\Mapping\ItemResult;
use App\Exchange\Service\Mapping\MappingResult;
use App\Exchange\Service\Mapping\MappingStrategyInterface;
use App\Message\Entity\Beds24SendQueue;
use App\Message\Entity\Message;
use App\Message\Service\MessageDataResolverRegistry;
use Vich\UploaderBundle\Storage\StorageInterface;

final readonly class Beds24SendMappingStrategy implements MappingStrategyInterface
{
    public function __construct(
        private MessageDataResolverRegistry $resolverRegistry,
        // 🔥 Inyectamos el Storage de Vich en lugar del project_dir
        private StorageInterface $vichStorage
    ) {}

    public function map(HomogeneousBatch $batch): MappingResult
    {
        $config = $batch->getConfig();
        $endpoint = $batch->getEndpoint();

        $fullUrl = rtrim($config->getBaseUrl(), '/') . '/' . ltrim((string)$endpoint->getEndpoint(), '/');
        $method = strtoupper((string)$endpoint->getMetodo());

        $payload = [];
        $correlation = [];

        foreach ($batch->getItems() as $index => $item) {
            /** @var Beds24SendQueue $item */
            $msg = $item->getMessage();
            if (!$msg instanceof Message) continue;

            $conversation = $msg->getConversation();
            $resolver = $this->resolverRegistry->getResolver($conversation->getContextType());

            $content = $msg->getContentExternal() ?? $msg->getContentLocal() ?? '';

            if ($resolver && str_contains($content, '{{')) {
                $variables = $resolver->getTemplateVariables($conversation->getContextId());
                foreach ($variables as $key => $value) {
                    $content = str_replace('{{ ' . $key . ' }}', (string)$value, $content);
                    $content = str_replace('{{' . $key . '}}', (string)$value, $content);
                }
            }

            $messagePayload = [
                'bookingId' => (int) $item->getTargetBookId(),
                'message'   => $content,
            ];

            // 3. Procesamiento del Archivo Adjunto usando Vich
            $attachment = $msg->getAttachments()->first() ?: null;

            if ($attachment && $attachment->isImage()) {
                // 🔥 MAGIA: Vich nos da la ruta absoluta real según tu vich_uploader.yaml
                $filePath = $this->vichStorage->resolvePath($attachment, 'file');

                if ($filePath && file_exists($filePath)) {
                    $fileContent = file_get_contents($filePath);

                    if ($fileContent !== false) {
                        $messagePayload['attachment'] = base64_encode($fileContent);
                        $messagePayload['attachmentName'] = $attachment->getOriginalName() ?? 'image.jpg';
                        $messagePayload['attachmentMimeType'] = $attachment->getMimeType() ?? 'image/jpeg';
                    }
                }
            }

            $payload[] = $messagePayload;
            $correlation[$index] = (string)$item->getId();
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

        foreach ($apiResponse as $index => $respData) {
            if (!isset($mapping->correlationMap[$index])) continue;

            $queueId = $mapping->correlationMap[$index];
            $success = (bool)($respData['success'] ?? false);

            $remoteId = $respData['id'] ?? $respData['new']['id'] ?? null;

            $results[$queueId] = new ItemResult(
                queueItemId: $queueId,
                success: $success,
                message: $success ? null : ($respData['message'] ?? 'Error desconocido al enviar mensaje'),
                remoteId: $remoteId ? (string)$remoteId : null,
                extraData: (array)$respData
            );
        }

        return $results;
    }
}