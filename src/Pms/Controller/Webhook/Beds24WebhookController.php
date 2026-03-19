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
        #[Autowire('%kernel.logs_dir%')] private readonly string $logsDir,
    ) {}

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
            // 🔥 LA MÁQUINA DEL TIEMPO INTELIGENTE (Ventana de 10 minutos) 🔥
            // =================================================================
            if (isset($payload['messages']) && is_array($payload['messages']) && !empty($payload['messages'])) {
                $lastMessage = end($payload['messages']);

                if (is_array($lastMessage) && ($lastMessage['source'] ?? '') === 'host' && !empty($lastMessage['time'])) {
                    try {
                        // PHP entiende automáticamente que la 'Z' final significa UTC
                        $msgTime = (new DateTimeImmutable($lastMessage['time']))->getTimestamp();
                        $now = time(); // time() siempre devuelve el timestamp universal actual

                        // Si la diferencia es de 10 minutos (600 segundos) o menos.
                        // abs() protege contra desincronización si el reloj de Beds24 está adelantado.
                        if (abs($now - $msgTime) <= 600) {
                            $stamps[] = new DelayStamp(15000); // 15s de ventaja a tu base de datos
                        }
                    } catch (Throwable) {
                        // Si por algún motivo la fecha viene corrupta, no retrasamos nada.
                    }
                }
            }

            // 3. ENCOLAMIENTO MAESTRO (Asíncrono total)
            $this->messageBus->dispatch(
                new ProcessBeds24WebhookDispatch((string) $audit->getId(), $rawContent, (string) $token),
                $stamps
            );

            // 4. RESPUESTA RELÁMPAGO (Cero bloqueos)
            return $this->prettyJson([
                'ok' => true,
                'status' => 'queued_for_processing',
                'audit_id' => $audit->getId(),
                'delayed' => !empty($stamps) // Opcional: Para que veas en la respuesta si aplicó el delay
            ], 200);

        } catch (Throwable $e) {
            $audit->setStatus(PmsBeds24WebhookAudit::STATUS_ERROR);
            $audit->setErrorMessage("Error de Parseo/Encolamiento: " . $e->getMessage());
            $this->persistAudit($audit);
            return $this->prettyJson(['ok' => false, 'error' => $e->getMessage()], 400);
        }
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