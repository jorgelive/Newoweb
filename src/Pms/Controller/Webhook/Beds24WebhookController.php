<?php

declare(strict_types=1);

namespace App\Pms\Controller\Webhook;

use App\Pms\Dispatch\ProcessBeds24WebhookDispatch;
use App\Pms\Entity\PmsBeds24WebhookAudit;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

#[Route('/pms/webhooks', name: 'webhook_beds24_')]
final class Beds24WebhookController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $messageBus,
    ) {}

    /**
     * Endpoint principal para la recepción de webhooks de Beds24.
     * * Propósito:
     * Recibir notificaciones de Beds24 (reservas y mensajes), auditarlas en crudo
     * y encolarlas para su procesamiento asíncrono.
     * * Lógica de Delay (Máquina del Tiempo):
     * Para evitar duplicados en la creación de "reservas espejo" debido a la
     * latencia entre la API y los webhooks, se aplica un retraso de 15s si:
     * 1. Hay mensajes y el último tiene menos de 10 minutos.
     * 2. No hay mensajes y la reserva/evento tiene menos de 10 minutos.
     * * @param Request $request
     * @return JsonResponse
     */
    #[Route('/endpoint', name: 'main_endpoint', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $rawContent = (string) $request->getContent();
        $token = $request->headers->get('X-Beds24-Webhook-Token') ?? $request->query->get('token');

        // 1. Auditoría Inicial (Raw)
        $audit = new PmsBeds24WebhookAudit();
        $audit->setReceivedAt(new DateTimeImmutable());
        $audit->setRemoteIp($request->getClientIp());
        $audit->setHeaders($request->headers->all());
        $audit->setPayloadRaw($rawContent);
        $audit->setStatus(PmsBeds24WebhookAudit::STATUS_QUEUED ?? 'queued');

        $this->persistAudit($audit);

        if (empty($token)) {
            $audit->setStatus(PmsBeds24WebhookAudit::STATUS_ERROR);
            $audit->setErrorMessage('missing_token');
            $this->persistAudit($audit);
            return $this->prettyJson(['ok' => false, 'error' => 'missing_token'], 403);
        }

        try {
            // 2. Parseo rápido solo para inspección de enrutamiento
            $payload = json_decode($rawContent, true, 512, JSON_THROW_ON_ERROR);
            $stamps = [];

            // =================================================================
            // 🔥 DETERMINACIÓN DEL TIEMPO DEL EVENTO 🔥
            // =================================================================
            $eventTimestamp = null;

            // CONDICIÓN A: Prioridad absoluta a los mensajes
            if (isset($payload['messages']) && is_array($payload['messages']) && !empty($payload['messages'])) {
                $lastMessage = end($payload['messages']);
                if (is_array($lastMessage) && !empty($lastMessage['time'])) {
                    try {
                        $eventTimestamp = (new DateTimeImmutable($lastMessage['time']))->getTimestamp();
                    } catch (Throwable) {
                        // Error de parseo, se mantiene null para ir al fallback
                    }
                }
            }

            // CONDICIÓN B: Si no hay mensajes, usamos los tiempos de la reserva/webhook
            if ($eventTimestamp === null) {
                // Primero intentamos con el timeStamp general del paquete
                if (!empty($payload['timeStamp'])) {
                    try {
                        $eventTimestamp = (new DateTimeImmutable($payload['timeStamp']))->getTimestamp();
                    } catch (Throwable) {}
                }
                // Si no, buscamos en la estructura de la reserva
                elseif (isset($payload['booking'])) {
                    try {
                        // Preferimos modifiedTime porque indica la última acción real
                        $timeStr = !empty($payload['booking']['modifiedTime'])
                            ? $payload['booking']['modifiedTime']
                            : ($payload['booking']['bookingTime'] ?? null);

                        if ($timeStr) {
                            $eventTimestamp = (new DateTimeImmutable($timeStr))->getTimestamp();
                        }
                    } catch (Throwable) {}
                }
            }

            // =================================================================
            // 🔥 EVALUACIÓN DE LA VENTANA DE 10 MINUTOS 🔥
            // =================================================================
            $applyDelay = true; // Por defecto aplicamos seguridad

            if ($eventTimestamp !== null) {
                $now = time(); // Timestamp UTC actual (independiente de Lima)

                // Si la diferencia es mayor a 600 segundos (10 minutos),
                // es un evento antiguo o un reintento. No penalizamos con delay.
                if (abs($now - $eventTimestamp) > 600) {
                    $applyDelay = false;
                }
            }

            if ($applyDelay) {
                $stamps[] = new DelayStamp(15000); // 15 segundos de colchón
            }

            // 3. ENCOLAMIENTO
            $this->messageBus->dispatch(
                new ProcessBeds24WebhookDispatch((string) $audit->getId(), $rawContent, (string) $token),
                $stamps
            );

            // 4. RESPUESTA RELÁMPAGO (Cero bloqueos)
            return $this->prettyJson([
                'ok' => true,
                'status' => 'queued_for_processing',
                'audit_id' => $audit->getId(),
                'delayed' => $applyDelay
            ], 200);

        } catch (Throwable $e) {
            $audit->setStatus(PmsBeds24WebhookAudit::STATUS_ERROR);
            $audit->setErrorMessage("Error de procesamiento: " . $e->getMessage());
            $this->persistAudit($audit);
            return $this->prettyJson(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * Responde en formato JSON formateado para debugging.
     */
    private function prettyJson(array $data, int $status = 200): JsonResponse
    {
        return (new JsonResponse($data, $status))
            ->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * Persiste de forma segura la auditoría del webhook.
     */
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