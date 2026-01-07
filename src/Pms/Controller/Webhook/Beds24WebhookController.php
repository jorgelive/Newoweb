<?php
declare(strict_types=1);

namespace App\Pms\Controller\Webhook;

use App\Pms\Service\Beds24\Webhook\Beds24BookingWebhookHandler;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

#[Route('/pms/webhooks', name: 'webhook_beds24_')]
final class Beds24WebhookController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Beds24BookingWebhookHandler $handler,
    ) {}

    #[Route('/bookings', name: 'bookings_v2', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $content = (string) $request->getContent();
        $payload = json_decode($content, true);

        $token = $request->headers->get('X-Beds24-Webhook-Token')
            ?? $request->query->get('token');

        if ($token === null || trim((string) $token) === '') {
            $this->connection->insert('pms_beds24_webhook_audit', [
                'received_at'    => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                'event_type'     => null,
                'remote_ip'      => $request->getClientIp(),
                'headers_json'   => json_encode($request->headers->all(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'payload_raw'    => $content,
                'payload_json'   => null,
                'status'         => 'error',
                'error_message'  => 'missing_token',
                'processing_meta'=> json_encode([
                    'received_token' => null,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null,
            ]);

            return new JsonResponse(['ok' => false, 'error' => 'missing_token'], 403);
        }

        if ($payload === null && json_last_error() !== JSON_ERROR_NONE) {
            // Auditoría mínima incluso para JSON inválido
            $this->connection->insert('pms_beds24_webhook_audit', [
                'received_at'    => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                'event_type'     => null,
                'remote_ip'      => $request->getClientIp(),
                'headers_json'   => json_encode($request->headers->all(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'payload_raw'    => $content,
                'payload_json'   => null,
                'status'         => 'error',
                'error_message'  => 'invalid_json',
                'processing_meta' => json_encode([
                    'received_token' => $token,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null,
            ]);

            return new JsonResponse(['ok' => false], 400);
        }

        $eventType = null;
        if (is_array($payload)) {
            $eventType = $payload['type'] ?? $payload['eventType'] ?? null;
            if ($eventType !== null && !is_string($eventType)) {
                $eventType = (string) $eventType;
            }
        }

        // 1) Insert inicial
        $this->connection->insert('pms_beds24_webhook_audit', [
            'received_at'    => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            'event_type'     => $eventType,
            'remote_ip'      => $request->getClientIp(),
            'headers_json'   => json_encode($request->headers->all(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null,
            'payload_raw'    => $content,
            'payload_json'   => is_array($payload) ? (json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null) : null,
            'status'         => 'received',
            'error_message'  => null,
            'processing_meta' => json_encode([
                'received_token' => $token,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null,
        ]);

        $auditId = (int) $this->connection->lastInsertId();

        // 2) Ejecutar (y si falla, actualizar auditoría)
        try {
            // ==========================================
            // Webhook v2 payload (Beds24)
            // - Por ahora procesamos SOLO "booking".
            // - Las demás secciones quedan como placeholders
            //   para implementar handlers separados.
            // ==========================================

            $bookingPayload = null;
            $infoItemsPayload = null;
            $invoiceItemsPayload = null;
            $messagesPayload = null;

            if (is_array($payload)) {
                $bookingPayload = (isset($payload['booking']) && is_array($payload['booking'])) ? $payload['booking'] : null;

                // Placeholders (no procesados aún):
                $infoItemsPayload = (isset($payload['infoItems']) && is_array($payload['infoItems'])) ? $payload['infoItems'] : null;
                $invoiceItemsPayload = (isset($payload['invoiceItems']) && is_array($payload['invoiceItems'])) ? $payload['invoiceItems'] : null;
                $messagesPayload = (isset($payload['messages']) && is_array($payload['messages'])) ? $payload['messages'] : null;
            }

            if ($bookingPayload === null) {
                throw new \RuntimeException('Webhook Beds24 inválido: falta booking.');
            }

            // Procesamos SOLO la sección booking.
            $meta = $this->handler->handle((string) $token, $bookingPayload);

            // TODO: implementar handlers separados cuando definas el comportamiento real.
            // if ($infoItemsPayload !== null) { ... }
            // if ($invoiceItemsPayload !== null) { ... }
            // if ($messagesPayload !== null) { ... }

            // En meta devolvemos también contadores para debug/auditoría.
            $meta = array_merge((array) $meta, [
                'sections' => [
                    'booking' => true,
                    'infoItems' => is_array($infoItemsPayload) ? count($infoItemsPayload) : 0,
                    'invoiceItems' => is_array($invoiceItemsPayload) ? count($invoiceItemsPayload) : 0,
                    'messages' => is_array($messagesPayload) ? count($messagesPayload) : 0,
                ],
            ]);

            $this->connection->update('pms_beds24_webhook_audit', [
                'status' => 'processed',
                'processing_meta' => (json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null),
                'error_message' => null,
            ], ['id' => $auditId]);
        } catch (\Throwable $e) {
            // Guardar el error en auditoría (sin romper el 200)
            $this->connection->update('pms_beds24_webhook_audit', [
                'status'         => 'error',
                'error_message'  => mb_substr($e->getMessage(), 0, 2000),
                'processing_meta'=> (json_encode([
                    'error' => 'process_failed',
                    'exception' => $e::class,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null),
            ], ['id' => $auditId]);
        }

        // Para webhooks: 200 siempre (si quieres 400 solo para invalid_json)
        return new JsonResponse(['ok' => true], 200);
    }
}