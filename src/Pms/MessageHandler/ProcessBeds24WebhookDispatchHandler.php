<?php

declare(strict_types=1);

namespace App\Pms\MessageHandler;

use App\Exchange\Entity\Beds24Config;
use App\Exchange\Service\Context\SyncContext;
use App\Message\Dto\Beds24MessageDto;
use App\Message\Service\Exchange\Tasks\Beds24Receive\Beds24ReceivePersister;
use App\Pms\Dispatch\ProcessBeds24WebhookDispatch;
use App\Pms\Entity\PmsBeds24WebhookAudit;
use App\Pms\Service\Beds24\Webhook\Beds24WebhookBookingFastTrackService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * Worker Asíncrono Principal para procesar los Webhooks de Beds24 en su totalidad.
 */
#[AsMessageHandler]
final readonly class ProcessBeds24WebhookDispatchHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private Beds24WebhookBookingFastTrackService $bookingService,
        private Beds24ReceivePersister $messagePersister,
        private SyncContext $syncContext,
        private LoggerInterface $logger
    ) {}

    public function __invoke(ProcessBeds24WebhookDispatch $dispatch): void
    {
        $audit = $this->em->getRepository(PmsBeds24WebhookAudit::class)->find($dispatch->auditId);
        if (!$audit instanceof PmsBeds24WebhookAudit) {
            return;
        }

        $config = $this->em->getRepository(Beds24Config::class)->findOneBy(['webhookToken' => $dispatch->token]);
        if (!$config instanceof Beds24Config || !$config->isActivo()) {
            $this->terminateWithError($audit, 'Token inválido o inactivo.');
            return;
        }

        $scope = $this->syncContext->enter(SyncContext::MODE_PULL, 'beds24');

        try {
            $payload = json_decode($dispatch->rawPayload, true, 512, JSON_THROW_ON_ERROR);

            $audit->setPayload($payload);
            $audit->setEventType($payload['type'] ?? $payload['eventType'] ?? 'unknown');

            $responseDetails = [];
            $globalErrors = [];

            // 1. PROCESAR BOOKINGS
            if (isset($payload['booking'])) {
                $bookingResult = $this->handleBookings($payload['booking'], $dispatch->token);
                $responseDetails['bookings'] = $bookingResult['processed'];
                $globalErrors = array_merge($globalErrors, $bookingResult['errors']);
            }

            // 2. PROCESAR MENSAJES (Con el Persister optimizado)
            if (isset($payload['messages'])) {
                $messageResult = $this->handleMessages($payload['messages']);
                $responseDetails['messages'] = $messageResult['processed'];
                $globalErrors = array_merge($globalErrors, $messageResult['errors']);
            }

            // 3. ACTUALIZAR AUDITORÍA
            $finalStatus = empty($globalErrors) ? PmsBeds24WebhookAudit::STATUS_PROCESSED : 'partial_error';
            if (!empty($globalErrors) && empty($responseDetails['bookings']) && empty($responseDetails['messages'])) {
                $finalStatus = PmsBeds24WebhookAudit::STATUS_ERROR;
            }

            $audit->setStatus($finalStatus);
            $audit->setProcessingMeta([
                'mode' => 'async_worker',
                'details' => $responseDetails,
                'errors' => $globalErrors
            ]);

        } catch (Throwable $e) {
            $this->terminateWithError($audit, "Error Crítico Worker: " . $e->getMessage());
            throw $e;
        } finally {
            $this->em->flush();
            $scope->restore();
            $this->em->clear();
        }
    }

    private function handleBookings(mixed $bookingData, string $token): array
    {
        $bookingsToProcess = is_array($bookingData) && array_is_list($bookingData) ? $bookingData : [$bookingData];
        $processedIds = [];
        $errors = [];

        foreach ($bookingsToProcess as $data) {
            if (!is_array($data) || !isset($data['id'])) continue;
            try {
                // Llama a tu servicio síncrono original, pero ahora corre en background
                $res = $this->bookingService->process($token, $data);
                $processedIds[] = $res['id'] ?? $data['id'];
            } catch (Throwable $e) {
                $errors[] = ['id' => $data['id'] ?? 'unknown', 'error' => $e->getMessage()];
            }
        }
        return ['processed' => $processedIds, 'errors' => $errors];
    }

    private function handleMessages(mixed $messagesData): array
    {
        $messagesToProcess = is_array($messagesData) && array_is_list($messagesData) ? $messagesData : [$messagesData];
        $processedIds = [];
        $errors = [];

        foreach ($messagesToProcess as $data) {
            if (!is_array($data) || !isset($data['id'])) continue;
            try {
                $dto = Beds24MessageDto::fromArray($data);
                if (!empty($dto->bookingId)) {
                    $this->messagePersister->upsertMessages((string) $dto->bookingId, [$dto]);
                    $processedIds[] = $dto->id;
                }
            } catch (Throwable $e) {
                $errors[] = ['message_id' => $data['id'] ?? 'unknown', 'error' => $e->getMessage()];
            }
        }
        return ['processed' => $processedIds, 'errors' => $errors];
    }

    private function terminateWithError(PmsBeds24WebhookAudit $audit, string $msg): void
    {
        $audit->setStatus(PmsBeds24WebhookAudit::STATUS_ERROR);
        $audit->setErrorMessage(mb_substr($msg, 0, 2000));
        $this->logger->error("Webhook Worker Error: " . $msg);
    }
}