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
        private StorageInterface $vichStorage
    ) {}

    /**
     * @param HomogeneousBatch $batch
     * @return MappingResult
     */
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
            if (!$msg instanceof Message) {
                continue;
            }

            $conversation = $msg->getConversation();
            $resolver = $this->resolverRegistry->getResolver($conversation->getContextType());

            // =================================================================
            // 1. EXTRACCIÓN DE CONTENIDO Y PLANTILLA
            // =================================================================
            $content = '';
            $template = $msg->getTemplate();

            if ($template !== null) {
                $language = $msg->getLanguageCode() ?: 'es';
                $content = (string) $template->getBeds24Body($language);
            }

            if (empty(trim($content))) {
                $content = $msg->getContentExternal() ?? $msg->getContentLocal() ?? '';
            }

            $subject = $msg->getSubjectExternal() ?? $msg->getSubjectLocal() ?? '';
            if (!empty(trim($subject))) {
                $content = trim($subject) . "\n\n" . trim($content);
            }

            // =================================================================
            // 2. INTERPOLACIÓN DE VARIABLES (ej. {{ guest_name }})
            // =================================================================
            if ($resolver !== null && str_contains($content, '{{')) {
                $variables = $resolver->getMessageVariables($conversation->getContextId());
                foreach ($variables as $key => $value) {
                    $content = str_replace('{{ ' . $key . ' }}', (string)$value, $content);
                    $content = str_replace('{{' . $key . '}}', (string)$value, $content);
                }
            }

            // =================================================================
            // 3. EXTRACCIÓN DEL ADJUNTO (Vich Uploader)
            // =================================================================
            $attachment = $msg->getAttachments()->first() ?: null;

            // 🔥 FIX: Prevención de rechazo por mensaje vacío en Beds24
            // Si no hay texto, pero SÍ hay adjunto, usamos el nombre del archivo.
            if (empty(trim($content)) && $attachment !== null) {
                $content = 'Archivo adjunto: ' . ($attachment->getOriginalName() ?? 'imagen.jpg');
            }

            // Red de seguridad final: Si por algún error de UI llega totalmente vacío (sin texto ni foto)
            if (empty(trim($content))) {
                $content = '(Mensaje sin texto)';
            }

            // =================================================================
            // 4. CONSTRUCCIÓN DEL PAYLOAD FINAL
            // =================================================================
            $messagePayload = [
                'bookingId' => (int) $item->getTargetBookId(),
                'message'   => trim($content),
            ];

            if ($attachment !== null && $attachment->isImage()) {
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

    /**
     * @param array $apiResponse
     * @param MappingResult $mapping
     * @return array<string, ItemResult>
     */
    public function parseResponse(array $apiResponse, MappingResult $mapping): array
    {
        $results = [];

        foreach ($apiResponse as $index => $respData) {
            if (!isset($mapping->correlationMap[$index])) {
                continue;
            }

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