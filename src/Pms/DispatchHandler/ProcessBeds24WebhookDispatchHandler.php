<?php

declare(strict_types=1);

namespace App\Pms\DispatchHandler;

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
use RuntimeException;

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

    /**
     * Procesa la solicitud encolada desde el webhook, ejecutando la lógica pesada
     * en segundo plano.
     *
     * @param ProcessBeds24WebhookDispatch $dispatch DTO con el payload original.
     * @return void
     * @throws Throwable Si ocurre un error crítico que el Worker deba reportar a la cola.
     */
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


            // 1. Extraemos los datos del objeto 'booking'
            $booking = $payload['booking'] ?? [];
            $bookingId = $booking['id'] ?? 'N/A';
            $guestName = trim(($booking['firstName'] ?? '') . ' ' . ($booking['lastName'] ?? ''));
            $channel = strtoupper($booking['referer'] ?? 'DIRECT');

            // 2. Contadores de sub-nodos
            $msgCount = count($payload['messages'] ?? []);
            $invCount = count($payload['invoiceItems'] ?? []);

            // 3. Construimos el string descriptivo
            // Ejemplo: "B24 #83116820 | ANA CAÑABATE LOPEZ | [B.COM] | MSGS: 6 | INVS: 1"
            $fullLabel = sprintf(
                "B24 #%s | %s | [%s] | MSGS: %d | INVS: %d",
                $bookingId,
                $guestName ?: 'GUEST',
                $channel,
                $msgCount,
                $invCount
            );

            // 4. Aplicamos substr de seguridad a 256 para el campo de la DB
            // Usamos mb_substr para proteger la integridad de los caracteres UTF-8
            $audit->setEventType(mb_substr($fullLabel, 0, 256));

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
            $finalStatus = empty($globalErrors) ? PmsBeds24WebhookAudit::STATUS_PROCESSED : PmsBeds24WebhookAudit::STATUS_PARTIAL_ERROR;
            if (!empty($globalErrors) && empty($responseDetails['bookings']) && empty($responseDetails['messages'])) {
                $finalStatus = PmsBeds24WebhookAudit::STATUS_ERROR;
            }

            $audit->setStatus($finalStatus);
            $audit->setProcessingMeta([
                'mode' => 'async_worker',
                'details' => $responseDetails,
                'errors' => $globalErrors
            ]);

            // 🔥 REGLA DE ORO: CHECK ANTES DEL FLUSH
            // Movemos el flush AQUÍ ADENTRO del try. Si el EM murió en los sub-procesos, abortamos
            // de inmediato y lanzamos la alerta, evitando el falso error "EntityManagerClosed".
            if (!$this->em->isOpen()) {
                $this->logger->critical("El EntityManager se cerró durante el procesamiento. Abortando guardado final.", [
                    'errores_acumulados' => $globalErrors
                ]);
                throw new RuntimeException("El proceso interno rompió la conexión a la base de datos.");
            }

            $this->em->flush();

        } catch (Throwable $e) {
            // 🔥 REGLA DE ORO: PASAR LA EXCEPCIÓN COMPLETA AL LOGGER
            $this->logger->error("Fallo crítico en Worker de Beds24", ['exception' => $e]);

            // Intentamos guardar el error en la auditoría si el EM sigue vivo
            if ($this->em->isOpen()) {
                $this->terminateWithError($audit, "Error Crítico Worker: " . $e->getMessage());
                try {
                    $this->em->flush();
                } catch (Throwable $flushException) {
                    $this->logger->critical("Imposible guardar estado de error en auditoría.", ['exception' => $flushException]);
                }
            }

            throw $e;
        } finally {
            $scope->restore();
            // Solo limpiamos si el EM sigue abierto
            if ($this->em->isOpen()) {
                $this->em->clear();
            }
        }
    }

    /**
     * Procesa el nodo de reservas de Beds24
     * * @param mixed $bookingData Nodo 'booking' del payload
     * @param string $token Token de seguridad del webhook
     * @return array
     */
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
                // 🔥 REGLA DE ORO: PASAR LA EXCEPCIÓN AL LOGGER PARA TENER EL TRACE
                $this->logger->error("Error procesando reserva Beds24 desde Worker", ['exception' => $e, 'booking_id' => $data['id']]);

                $errors[] = [
                    'id' => $data['id'] ?? 'unknown',
                    'error' => $e->getMessage()
                ];
            }
        }
        return ['processed' => $processedIds, 'errors' => $errors];
    }

    /**
     * Procesa los mensajes recibidos del huésped en el canal de Beds24.
     * * @param mixed $messagesData Nodo 'messages' del payload
     * @return array
     */
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
                // 🔥 REGLA DE ORO: PASAR LA EXCEPCIÓN AL LOGGER
                $this->logger->error("Error procesando mensaje Beds24 desde Worker", ['exception' => $e, 'message_id' => $data['id']]);

                $errors[] = [
                    'message_id' => $data['id'] ?? 'unknown',
                    'error' => $e->getMessage()
                ];
            }
        }
        return ['processed' => $processedIds, 'errors' => $errors];
    }

    /**
     * Actualiza el estado de la auditoría cuando ocurre un error grave.
     * * @param PmsBeds24WebhookAudit $audit Entidad de auditoría
     * @param string $msg Mensaje de error para registrar
     * @return void
     */
    private function terminateWithError(PmsBeds24WebhookAudit $audit, string $msg): void
    {
        $audit->setStatus(PmsBeds24WebhookAudit::STATUS_ERROR);
        $audit->setErrorMessage(mb_substr($msg, 0, 2000, 'UTF-8'));
        // Ya no es necesario loguear como error aquí porque lo logueamos en el catch principal con el Stack Trace completo.
    }
}