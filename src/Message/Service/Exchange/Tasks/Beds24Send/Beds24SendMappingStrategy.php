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

            // =================================================================
            // 🔥 ESCENARIO A: RECIBO DE LECTURA (READ RECEIPT)
            // Si el mensaje es entrante, significa que estamos mandando un update
            // a Beds24 para decirle que el host ya leyó este mensaje.
            // =================================================================
            if ($msg->getDirection() === Message::DIRECTION_INCOMING) {
                $extId = $msg->getBeds24ExternalId();

                // Si el mensaje no tiene ID en Beds24, es imposible marcarlo.
                if ($extId) {
                    $payload[] = [
                        'id' => (int) $extId,
                        'read' => true
                    ];
                    $correlation[$index] = (string)$item->getId();
                }
                continue;
            }

            // =================================================================
            // ⬇️ ESCENARIO B: ENVÍO DE MENSAJE NUEVO (OUTGOING) ⬇️
            // =================================================================
            $conversation = $msg->getConversation();
            $resolver = $this->resolverRegistry->getResolver($conversation->getContextType());

            $idiomaEntity = $conversation->getIdioma();
            $internalLang = strtolower($idiomaEntity->getId());
            // NUEVO: Bifurcación de idiomas. El texto libre usa $internalLang, pero las plantillas usan $templateLang.
            $templateLang = ($idiomaEntity->getPrioridad() > 0) ? $internalLang : 'en';

            // 1. EXTRACCIÓN DE CONTENIDO Y PLANTILLA
            $content = '';
            $template = $msg->getTemplate();

            if ($template !== null) {
                $content = (string) $template->getBeds24Body($templateLang);
            }

            if (empty(trim($content))) {
                // Aquí usamos el texto de Google Translate sin importar si es Hebreo o Japonés.
                $content = $msg->getContentExternal() ?? $msg->getContentLocal() ?? '';
            }

            $subject = $msg->getSubjectExternal() ?? $msg->getSubjectLocal() ?? '';
            if (!empty(trim($subject))) {
                $content = trim($subject) . "\n\n" . trim($content);
            }

            // Extraemos las variables una sola vez para usarlas en texto y botones
            $variables = $resolver !== null ? $resolver->getMessageVariables($conversation->getContextId()) : [];

            // 2. INTERPOLACIÓN DE VARIABLES (Soporta {{ var }} y {{var}})
            if (!empty($variables) && str_contains($content, '{{')) {
                $content = preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', function ($matches) use ($variables) {
                    $key = trim($matches[1]);
                    // Si la variable existe en nuestro resolver, la ponemos; si no, dejamos el tag original
                    return array_key_exists($key, $variables) ? (string)$variables[$key] : $matches[0];
                }, $content);
            }

            // 3. 🎯 RENDERIZADO OMNICANAL DE BOTONES (UX MEJORADO)
            // 🔥 VALIDACIÓN: Verificamos si el usuario bloqueó la generación de botones para Beds24
            $disableMetaButtons = $template !== null && $template->isBeds24MetaButtonsDisabled();

            if (!$disableMetaButtons && $template !== null) {
                $metaJson = $template->getWhatsappMetaTmpl();

                if (!empty($metaJson['buttons_map'])) {

                    // Detección Inteligente: ¿Hay opciones para interactuar o son puros links?
                    $hasQuickReplies = false;
                    foreach ($metaJson['buttons_map'] as $btn) {
                        if (strtolower($btn['type'] ?? '') === 'quick_reply') {
                            $hasQuickReplies = true;
                            break;
                        }
                    }

                    $menuTexts = $this->getMenuTranslations($templateLang);
                    $metaLang = $this->normalizeLanguageForMeta($templateLang);

                    // Inyectamos el título semánticamente correcto
                    $headerTitle = $hasQuickReplies ? $menuTexts['header_options'] : $menuTexts['header_links'];
                    $content .= "\n\n" . $headerTitle . "\n\n";

                    $quickReplyIndex = 1;

                    foreach ($metaJson['buttons_map'] as $btn) {
                        $btnType = strtolower($btn['type'] ?? '');

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
                                $content .= "🔗 *" . trim($btnText) . "*:\n" . $urlValue . "\n\n";
                            }
                        } elseif ($btnType === 'quick_reply') {
                            $content .= $quickReplyIndex . "️⃣ *" . trim($btnText) . "*\n\n";
                            $quickReplyIndex++;
                        }
                    }

                    // Inyectamos el footer dinámico SOLO si hay Quick Replies reales
                    if ($hasQuickReplies) {
                        $content = rtrim($content) . "\n\n" . $menuTexts['footer'];
                    } else {
                        $content = rtrim($content);
                    }
                }
            }

            // 4. EXTRACCIÓN DEL ADJUNTO (Vich Uploader)
            $attachment = $msg->getAttachments()->first() ?: null;

            // FIX: Prevención de rechazo por mensaje vacío en Beds24
            if (empty(trim($content)) && $attachment !== null) {
                $content = 'Archivo adjunto: ' . ($attachment->getOriginalName() ?? 'imagen.jpg');
            }

            if (empty(trim($content))) {
                $content = '(Mensaje sin texto)';
            }

            // 5. CONSTRUCCIÓN DEL PAYLOAD FINAL
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
                'default_btn' => 'Option'
            ],
            'pt' => [
                'header_options' => '*— Opções —*',
                'header_links'   => '*— Links —*',
                'footer'         => '_👉 Responda com o número da sua opção._',
                'default_btn' => 'Opção'
            ],
            'fr' => [
                'header_options' => '*— Options —*',
                'header_links'   => '*— Liens —*',
                'footer'         => '_👉 Répondez avec le numéro de votre option._',
                'default_btn' => 'Option'
            ],
            'it' => [
                'header_options' => '*— Opzioni —*',
                'header_links'   => '*— Link —*',
                'footer'         => '_👉 Rispondi con il numero della tua opzione._',
                'default_btn' => 'Opzione'
            ],
            'de' => [
                'header_options' => '*— Optionen —*',
                'header_links'   => '*— Links —*',
                'footer'         => '_👉 Antworten Sie mit der Nummer Ihrer Option._',
                'default_btn' => 'Option'
            ],
            'nl' => [
                'header_options' => '*— Opties —*',
                'header_links'   => '*— Links —*',
                'footer'         => '_👉 Antwoord met het nummer van uw optie._',
                'default_btn' => 'Optie'
            ]
        ];

        return $translations[$lang] ?? $translations['en'];
    }

    /**
     * Normaliza el idioma local al estándar de Meta Cloud API.
     */
    private function normalizeLanguageForMeta(string $lang): string
    {
        $map = ['pt' => 'pt_BR'];
        return $map[$lang] ?? $lang;
    }
}