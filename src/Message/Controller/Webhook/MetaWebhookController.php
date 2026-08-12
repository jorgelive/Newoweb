<?php

declare(strict_types=1);

namespace App\Message\Controller\Webhook;

use App\Exchange\Entity\MetaConfig;
use App\Message\Entity\MetaWebhookAudit;
use App\Message\Service\Meta\Webhook\WhatsappMetaWebhookMessageFastTrackService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

#[Route('/message/meta/webhook', name: 'message_meta_webhook_')]
final class MetaWebhookController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WhatsappMetaWebhookMessageFastTrackService $fastTrackService,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.logs_dir%')] private readonly string $logsDir,
    ) {}

    /**
     * Endpoint de Verificación (GET): Requerido por Meta al configurar el Webhook en el Business Manager.
     */
    #[Route('/endpoint', name: 'verify', methods: ['GET'])]
    public function verify(Request $request): Response
    {
        $mode = $request->query->get('hub_mode');
        $token = $request->query->get('hub_verify_token');
        $challenge = $request->query->get('hub_challenge');

        $config = $this->em->getRepository(MetaConfig::class)->findOneBy(['activo' => true]);
        $verifyTokenDb = $config?->getCredential('verifyToken');

        if ($mode === 'subscribe' && $token === $verifyTokenDb) {
            return new Response((string)$challenge, 200);
        }

        return new Response('Forbidden', 403);
    }

    /**
     * ¿Viene el cuerpo firmado con nuestro App Secret?
     *
     * `hash_equals()` y no `===`: la comparación de un HMAC se hace en tiempo constante o se
     * filtra por dónde empieza a diferir.
     *
     * ⚠️ **Sin secreto configurado, DEJA PASAR y avisa.** Es la única concesión, y es
     * deliberada: hoy `exchange_meta_config` no guarda `appSecret`, así que fallar cerrado
     * dejaría el WhatsApp mudo en el mismo despliegue que introduce la comprobación. En cuanto
     * se pegue el secreto en el panel, esto pasa a rechazar solo. El `error` del log está para
     * que ese estado no se quede ahí para siempre.
     */
    private function firmaValida(Request $request, string $rawContent): bool
    {
        $config = $this->em->getRepository(MetaConfig::class)->findOneBy(['activo' => true]);
        $secreto = $config?->getAppSecret();

        if ($secreto === null) {
            $this->logger->error(
                '[MetaWebhook] SIN App Secret configurado: no se puede validar la firma y se '
                . 'acepta cualquier payload. Pégalo en el panel (Meta → Configuración → Básica).'
            );

            return true;
        }

        $recibida = (string) $request->headers->get('X-Hub-Signature-256');

        if ($recibida === '') {
            return false;
        }

        return hash_equals('sha256=' . hash_hmac('sha256', $rawContent, $secreto), $recibida);
    }

    /**
     * Endpoint Principal (POST): Enrutador de eventos de Meta (WhatsApp, IG, Messenger).
     */
    #[Route('/endpoint', name: 'main_endpoint', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        // 1. Captura absoluta del contenido crudo
        $rawContent = (string) $request->getContent();

        // 🔒 ¿LO MANDA META DE VERDAD?
        //
        // Sin esta comprobación el endpoint se cree cualquier POST que le llegue. Y no es
        // teórico: el actor del asistente se resuelve por el `wa_id` del remitente, así que
        // quien conozca la URL puede mandarse un payload con el número de un operador y
        // recibir de vuelta las salidas del día, saldos de reservas y ocupación. El comentario
        // de `AiConversationProcessor` —«el teléfono identifica pero no autentica»— se
        // sostenía en que el número lo verificaba Meta; sólo es verdad si se mira la firma.
        //
        // Se comprueba ANTES de auditar y de despachar nada, pero DESPUÉS de leer el cuerpo:
        // el HMAC va sobre el raw exacto.
        if (!$this->firmaValida($request, $rawContent)) {
            $this->logger->error('[MetaWebhook] Firma inválida; petición rechazada.', [
                'ip' => $request->getClientIp(),
            ]);

            return new JsonResponse(['ok' => false, 'error' => 'firma_invalida'], Response::HTTP_FORBIDDEN);
        }

        // 2. Escritura a disco para diagnóstico
        file_put_contents(
            $this->logsDir . '/meta_whatsapp_debug.json',
            $rawContent . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );

        // 3. Auditoría Inicial — guardamos el raw SIN TOCAR antes de cualquier procesamiento
        $audit = new MetaWebhookAudit();
        $audit->setReceivedAt(new DateTimeImmutable());
        $audit->setRemoteIp($request->getClientIp());
        $audit->setHeaders($request->headers->all());
        $audit->setPayloadRaw($rawContent);
        $this->persistAudit($audit);

        try {
            // Decodificamos el JSON
            $payload = json_decode($rawContent, true, 512, JSON_THROW_ON_ERROR);
            $audit->setPayload($payload);

            // Meta suele enviar el canal en la llave 'object' (ej. 'whatsapp_business_account')
            if (is_array($payload)) {
                $audit->setEventType($payload['object'] ?? 'unknown');
            }

            $this->persistAudit($audit);

            // =================================================================
            // ENRUTAMIENTO (ROUTING OMNICANAL)
            // =================================================================

            $responseDetails = [];
            $globalErrors = [];
            $processedAny = false;

            if (isset($payload['entry'])) {
                foreach ($payload['entry'] as $entry) {
                    foreach ($entry['changes'] as $change) {
                        $value = $change['value'];

                        // 1. MESSAGES (El huésped escribe)
                        if (isset($value['messages']) && isset($value['contacts'])) {
                            $contactData = $value['contacts'][0];

                            foreach ($value['messages'] as $messageData) {
                                try {
                                    $res = $this->fastTrackService->processMessage($messageData, $contactData);
                                    $responseDetails['messages'][] = $res['id'];
                                } catch (Throwable $e) {
                                    $globalErrors[] = [
                                        'type' => 'message',
                                        'id' => $messageData['id'] ?? 'unknown',
                                        'error' => $e->getMessage()
                                    ];
                                }
                            }
                            $processedAny = true;
                        }

                        if (isset($value['calls'])) {
                            foreach ($value['calls'] as $callData) {
                                try {
                                    // Pasamos la llamada al fastTrackService
                                    $res = $this->fastTrackService->processCall($callData, $value['contacts'][0] ?? []);
                                    $responseDetails['calls'][] = $res['id'];
                                } catch (Throwable $e) {
                                    $globalErrors[] = ['type' => 'call', 'id' => $callData['id'] ?? 'unknown', 'error' => $e->getMessage()];
                                }
                            }
                            $processedAny = true;
                        }

                        // 2. STATUSES (Confirmaciones de lectura/entrega)
                        if (isset($value['statuses'])) {
                            foreach ($value['statuses'] as $statusData) {
                                try {
                                    $res = $this->fastTrackService->processStatus($statusData);
                                    $responseDetails['statuses'][] = $res['id'];
                                } catch (Throwable $e) {
                                    $globalErrors[] = [
                                        'type' => 'status',
                                        'id' => $statusData['id'] ?? 'unknown',
                                        'error' => $e->getMessage()
                                    ];
                                }
                            }
                            $processedAny = true;
                        }
                    }
                }
            }

            // =================================================================
            // CIERRE DE AUDITORÍA
            // =================================================================

            if (!$processedAny) {
                $this->logger->info('Payload de Meta sin datos procesables (sin messages ni statuses).');
            }

            $finalStatus = empty($globalErrors) ? MetaWebhookAudit::STATUS_PROCESSED : MetaWebhookAudit::STATUS_PARTIAL_ERROR;

            if (!empty($globalErrors) && empty($responseDetails['messages']) && empty($responseDetails['statuses'])) {
                $finalStatus = MetaWebhookAudit::STATUS_ERROR;
            }

            $audit->setStatus($finalStatus);
            $audit->setProcessingMeta([
                'mode' => 'controller_router',
                'details' => $responseDetails,
                'errors' => $globalErrors
            ]);

            if (!empty($globalErrors)) {
                $audit->setErrorMessage('Errores: ' . json_encode($globalErrors, JSON_UNESCAPED_UNICODE));
            }

            $this->persistAudit($audit);

            // CRÍTICO: Meta SIEMPRE requiere un HTTP 200 OK.
            // Si devuelves 4xx o 5xx, reintentará indefinidamente y bloqueará la app.
            return $this->prettyJson([
                'ok' => true,
                'details' => $responseDetails,
                'errors' => $globalErrors
            ], 200);

        } catch (JsonException $e) {
            $this->terminateWithError($audit, "JSON Inválido en Meta Webhook: " . $e->getMessage(), 200);
            return $this->prettyJson(['ok' => false, 'error' => 'invalid_json'], 200);
        } catch (Throwable $e) {
            $this->terminateWithError($audit, "Error crítico: " . $e->getMessage(), 200);
            return $this->prettyJson(['ok' => false, 'error' => $e->getMessage()], 200);
        }
    }

    // =========================================================================
    // HELPERS PRIVADOS
    // =========================================================================

    private function terminateWithError(MetaWebhookAudit $audit, string $msg, int $httpCode): void
    {
        $audit->setStatus(MetaWebhookAudit::STATUS_ERROR);
        $audit->setErrorMessage(mb_substr($msg, 0, 2000));
        $this->persistAudit($audit);
    }

    private function prettyJson(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status)
            ->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    private function persistAudit(MetaWebhookAudit $audit): void
    {
        if (!$this->em->isOpen()) return;
        if (!$this->em->contains($audit)) {
            $this->em->persist($audit);
        }
        $this->em->flush();
    }
}