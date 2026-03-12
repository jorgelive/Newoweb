<?php
declare(strict_types=1);

namespace App\Pms\Controller\Webhook;

use App\Pms\Entity\PmsBeds24WebhookAudit;
use App\Pms\Service\Beds24\Webhook\Beds24WebhookBookingFastTrackService;
use App\Message\Service\Beds24\Webhook\Beds24WebhookMessageFastTrackService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/pms/webhooks', name: 'webhook_beds24_')]
final class Beds24WebhookController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Beds24WebhookBookingFastTrackService $bookingService,
        private readonly Beds24WebhookMessageFastTrackService $messageService,
    ) {}

    #[Route('/endpoint', name: 'main_endpoint', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        // 1. Obtener el contenido original
        $rawContent = (string) $request->getContent();

        // 🔥 FIX DE CODIFICACIÓN PARA EMOJIS DE BEDS24 🔥
        // Beds24 a veces envía el payload sin el charset estricto UTF-8.
        // Si detectamos que no es UTF-8 nativo, lo convertimos para salvar los iconos (📍, 🏠, etc.)
        $currentEncoding = mb_detect_encoding($rawContent, 'UTF-8, ISO-8859-1', true);
        if ($currentEncoding !== 'UTF-8') {
            $rawContent = mb_convert_encoding($rawContent, 'UTF-8', $currentEncoding ?: 'ISO-8859-1');
        }

        // 2. Auditoría Inicial (Guardamos el contenido ya sanitizado a UTF-8)
        $audit = new PmsBeds24WebhookAudit();
        $audit->setReceivedAt(new DateTimeImmutable());
        $audit->setRemoteIp($request->getClientIp());
        $audit->setHeaders($request->headers->all());
        $audit->setPayloadRaw($rawContent);

        $token = $request->headers->get('X-Beds24-Webhook-Token') ?? $request->query->get('token');

        if (empty($token)) {
            $this->terminateWithError($audit, 'missing_token', 403);
            return $this->prettyJson(['ok' => false, 'error' => 'missing_token'], 403);
        }

        try {
            // Decodificamos el contenido ya forzado a UTF-8
            $payload = json_decode($rawContent, true, 512, JSON_THROW_ON_ERROR);
            $audit->setPayload($payload);

            if (is_array($payload)) {
                $audit->setEventType($payload['type'] ?? $payload['eventType'] ?? 'unknown');
            }
            $this->persistAudit($audit);

            // =================================================================
            // ENRUTAMIENTO (ROUTING)
            // =================================================================

            $responseDetails = [];
            $globalErrors = [];
            $processedAny = false;

            // 1. BOOKINGS
            if (isset($payload['booking'])) {
                $bookingResult = $this->handleBookings($payload['booking'], (string)$token);
                $responseDetails['bookings'] = $bookingResult['processed'];
                if (!empty($bookingResult['errors'])) {
                    $globalErrors = array_merge($globalErrors, $bookingResult['errors']);
                }
                $processedAny = true;
            }

            // 2. MESSAGES
            if (isset($payload['messages'])) {
                $messageResult = $this->handleMessages($payload['messages'], (string)$token);
                $responseDetails['messages'] = $messageResult['processed'];
                if (!empty($messageResult['errors'])) {
                    $globalErrors = array_merge($globalErrors, $messageResult['errors']);
                }
                $processedAny = true;
            }

            // 3. INVOICE ITEMS (Futuro)
            if (isset($payload['invoiceItems'])) {
                $responseDetails['invoices'] = 'pending_implementation';
                $processedAny = true;
            }

            // =================================================================
            // CIERRE DE AUDITORÍA
            // =================================================================

            if (!$processedAny) {
                throw new \RuntimeException('Payload sin datos reconocibles (booking, messages, invoiceItems).');
            }

            $finalStatus = empty($globalErrors) ? PmsBeds24WebhookAudit::STATUS_PROCESSED : 'partial_error';

            if (!empty($globalErrors) && empty($responseDetails['bookings']) && empty($responseDetails['messages'])) {
                $finalStatus = PmsBeds24WebhookAudit::STATUS_ERROR;
            }

            $audit->setStatus($finalStatus);
            $audit->setProcessingMeta([
                'mode' => 'controller_router',
                'details' => $responseDetails,
                'errors' => $globalErrors
            ]);

            if (!empty($globalErrors)) {
                $audit->setErrorMessage('Errores: ' . json_encode($globalErrors));
            }

            $this->persistAudit($audit);

            return $this->prettyJson([
                'ok' => empty($globalErrors),
                'details' => $responseDetails,
                'errors' => $globalErrors
            ], 200);

        } catch (\JsonException $e) {
            $this->terminateWithError($audit, "JSON Inválido: " . $e->getMessage(), 400);
            return $this->prettyJson(['ok' => false, 'error' => 'invalid_json'], 400);
        } catch (\Throwable $e) {
            $this->terminateWithError($audit, $e->getMessage(), 200);
            return $this->prettyJson(['ok' => false, 'error' => $e->getMessage()], 200);
        }
    }

    // ... el resto del controlador (handleBookings, handleMessages, etc.) se mantiene exactamente igual ...

    private function handleBookings(mixed $bookingData, string $token): array
    {
        $bookingsToProcess = [];

        if (is_array($bookingData)) {
            if (array_is_list($bookingData)) {
                $bookingsToProcess = $bookingData;
            } else {
                $bookingsToProcess = [$bookingData];
            }
        }

        $processedIds = [];
        $errors = [];

        foreach ($bookingsToProcess as $index => $data) {
            if (!is_array($data) || !isset($data['id'])) {
                $errors[] = ['index' => $index, 'error' => 'Estructura inválida'];
                continue;
            }

            try {
                $res = $this->bookingService->process($token, $data);
                $processedIds[] = $res['id'];
            } catch (\Throwable $e) {
                $errors[] = [
                    'id' => $data['id'] ?? 'unknown',
                    'error' => $e->getMessage()
                ];
            }
        }

        return ['processed' => $processedIds, 'errors' => $errors];
    }

    private function handleMessages(mixed $messagesData, string $token): array
    {
        $messagesToProcess = [];

        if (is_array($messagesData)) {
            if (array_is_list($messagesData)) {
                $messagesToProcess = $messagesData;
            } else {
                $messagesToProcess = [$messagesData];
            }
        }

        $processedIds = [];
        $errors = [];

        foreach ($messagesToProcess as $index => $data) {
            if (!is_array($data) || !isset($data['id'])) {
                $errors[] = ['index' => $index, 'error' => 'Estructura de mensaje inválida'];
                continue;
            }

            try {
                $res = $this->messageService->process($token, $data);
                $processedIds[] = $res['id'];
            } catch (\Throwable $e) {
                $errors[] = [
                    'message_id' => $data['id'] ?? 'unknown',
                    'error' => $e->getMessage()
                ];
            }
        }

        return ['processed' => $processedIds, 'errors' => $errors];
    }

    private function terminateWithError(PmsBeds24WebhookAudit $audit, string $msg, int $httpCode): void
    {
        $audit->setStatus(PmsBeds24WebhookAudit::STATUS_ERROR);
        $audit->setErrorMessage(mb_substr($msg, 0, 2000));
        $this->persistAudit($audit);
    }

    private function prettyJson(array $data, int $status = 200): JsonResponse
    {
        return (new JsonResponse($data, $status))
            ->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    private function persistAudit(PmsBeds24WebhookAudit $audit): void
    {
        if (!$this->em->isOpen()) return;
        if (!$this->em->contains($audit)) $this->em->persist($audit);
        $this->em->flush();
    }
}