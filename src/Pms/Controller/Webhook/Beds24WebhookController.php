<?php
declare(strict_types=1);

namespace App\Pms\Controller\Webhook;

use App\Pms\Entity\PmsBeds24WebhookAudit;
use App\Pms\Service\Beds24\Webhook\Beds24WebhookFastTrackService;
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
        private readonly Beds24WebhookFastTrackService $fastTrackService,
    ) {}

    #[Route('/bookings', name: 'bookings_v2', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        // 1. Auditoría Inicial (Siempre guardamos lo que llega)
        $audit = new PmsBeds24WebhookAudit();
        $audit->setReceivedAt(new DateTimeImmutable());
        $audit->setRemoteIp($request->getClientIp());
        $audit->setHeaders($request->headers->all());

        $content = (string) $request->getContent();
        $audit->setPayloadRaw($content);

        // Extracción de Token (Header o Query)
        $token = $request->headers->get('X-Beds24-Webhook-Token')
            ?? $request->query->get('token');

        if (empty($token)) {
            $audit->setStatus(PmsBeds24WebhookAudit::STATUS_ERROR);
            $audit->setErrorMessage('missing_token');
            $this->persistAudit($audit);

            // Usamos el helper para respuesta legible
            return $this->prettyJson(['ok' => false, 'error' => 'missing_token'], 403);
        }

        try {
            $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            $audit->setPayload($payload);

            // Extracción de EventType para índice
            if (is_array($payload)) {
                $eventType = $payload['type'] ?? $payload['eventType'] ?? null;
                $audit->setEventType(is_string($eventType) ? $eventType : null);
            }

            // Persistimos el "Recibido" antes de procesar para tener traza si el proceso muere
            $this->persistAudit($audit);

            // ==========================================
            // Lógica FAST TRACK v2
            // ==========================================

            // Validamos estructura mínima (booking es obligatorio)
            if (!isset($payload['booking']) || !is_array($payload['booking'])) {
                throw new \RuntimeException('Webhook Beds24 inválido: falta objeto "booking".');
            }

            // Delegamos al servicio FastTrack
            // Este servicio actualiza el estado de $audit a 'processed' o 'error'
            $result = $this->fastTrackService->process((string)$token, $payload['booking'], $audit);

            // Actualizamos la auditoría final
            $this->persistAudit($audit);

            return $this->prettyJson(array_merge(['ok' => true], $result));

        } catch (\JsonException $e) {
            $audit->setStatus(PmsBeds24WebhookAudit::STATUS_ERROR);
            $audit->setErrorMessage("JSON Inválido: " . $e->getMessage());
            $this->persistAudit($audit);

            return $this->prettyJson(['ok' => false, 'error' => 'invalid_json'], 400);

        } catch (\Throwable $e) {
            // El servicio FastTrack ya debería haber marcado el audit como error,
            // pero por si la excepción vino de otro lado, aseguramos.
            $audit->setStatus(PmsBeds24WebhookAudit::STATUS_ERROR);
            $audit->setErrorMessage(mb_substr($e->getMessage(), 0, 2000));

            $this->persistAudit($audit);

            // Retornamos 200 OK para que Beds24 no reintente infinitamente si es un error lógico nuestro.
            // Solo retornamos 500 si queremos que reintente.
            return $this->prettyJson(['ok' => false, 'error' => $e->getMessage()], 200);
        }
    }

    /**
     * Helper para devolver JSON limpio, sin escapar caracteres Unicode ni Slashes.
     */
    private function prettyJson(array $data, int $status = 200): JsonResponse
    {
        return (new JsonResponse($data, $status))
            ->setEncodingOptions(
                JsonResponse::DEFAULT_ENCODING_OPTIONS |
                JSON_UNESCAPED_UNICODE | // Tildes y Ñ legibles
                JSON_UNESCAPED_SLASHES | // URLs limpias
                JSON_PRETTY_PRINT        // Formato visual agradable
            );
    }

    private function persistAudit(PmsBeds24WebhookAudit $audit): void
    {
        if (!$this->em->isOpen()) {
            return;
        }

        if (!$this->em->contains($audit)) {
            $this->em->persist($audit);
        }
        $this->em->flush();
    }
}