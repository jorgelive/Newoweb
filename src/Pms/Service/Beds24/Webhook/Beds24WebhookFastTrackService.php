<?php
declare(strict_types=1);

namespace App\Pms\Service\Beds24\Webhook;

use App\Exchange\Service\Context\SyncContext;
use App\Pms\Dto\Beds24BookingDto;
use App\Pms\Entity\Beds24Config;
use App\Pms\Entity\PmsBeds24WebhookAudit;
use App\Pms\Service\Exchange\Persister\Beds24BookingPersister;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Throwable;

final class Beds24WebhookFastTrackService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Beds24BookingPersister $persister, // <--- Usamos el nuevo Persister
        private readonly SyncContext $syncContext,
    ) {}

    /**
     * Procesa el payload del webhook INMEDIATAMENTE (sin cola).
     */
    public function process(string $token, array $payloadRaw, PmsBeds24WebhookAudit $audit): array
    {
        // 1. Validar Token y Configuración
        $config = $this->em->getRepository(Beds24Config::class)->findOneBy(['webhookToken' => $token]);

        if (!$config instanceof Beds24Config) {
            throw new RuntimeException("Token de webhook inválido o configuración no encontrada.");
        }

        if (!$config->isActivo()) {
            throw new RuntimeException("La configuración '{$config->getNombre()}' está inactiva.");
        }

        // 2. Ejecutar dentro del Contexto PULL (Evita loops de listeners)
        // Usamos el helper enterSource() que devuelve un scope seguro.
        $scope = $this->syncContext->enter(
            SyncContext::MODE_PULL,
            'beds24'
        );

        $conn = $this->em->getConnection();
        $conn->beginTransaction();

        try {
            // 3. Convertir a DTO
            // Beds24 a veces envía un array de bookings o un solo objeto. Normalizamos.
            // Si es v2 y viene un array, procesamos el primero o iteramos.
            // Asumimos estructura simple o array de 1 elemento para fast track.
            $bookingData = isset($payloadRaw[0]) ? $payloadRaw[0] : $payloadRaw;

            $dto = Beds24BookingDto::fromArray($bookingData);

            // 4. Persistencia (Upsert)
            $this->persister->upsert($config, $dto);

            // 5. Commit y Auditoría
            $this->em->flush();
            $conn->commit();

            // Actualizamos la auditoría
            $audit->setStatus(PmsBeds24WebhookAudit::STATUS_PROCESSED);
            $audit->setProcessingMeta([
                'mode' => 'fast_track',
                'booking_id' => $dto->id,
                'action' => 'upsert'
            ]);

            return ['success' => true, 'id' => $dto->id];

        } catch (Throwable $e) {
            $conn->rollBack();

            // Actualizamos auditoría con el error
            $audit->setStatus(PmsBeds24WebhookAudit::STATUS_ERROR);
            $audit->setErrorMessage($e->getMessage());

            // Re-lanzamos para que el controller decida el código HTTP (o retorne 200 pero loguee error)
            throw $e;
        } finally {
            $scope->restore();
        }
    }
}