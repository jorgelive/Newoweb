<?php
declare(strict_types=1);

namespace App\Pms\Service\Beds24\Webhook;

use App\Exchange\Service\Context\SyncContext;
use App\Pms\Dto\Beds24BookingDto;
use App\Pms\Entity\Beds24Config;
use App\Pms\Service\Exchange\Persister\Beds24BookingPersister;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Throwable;

final class Beds24WebhookBookingFastTrackService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Beds24BookingPersister $persister,
        private readonly SyncContext $syncContext,
    ) {}

    /**
     * Procesa UNA sola reserva.
     * @throws Throwable Si algo falla, el Controller captura y loguea.
     */
    public function process(string $token, array $bookingData): array
    {
        // 1. Validar Token y Configuración
        $config = $this->em->getRepository(Beds24Config::class)->findOneBy(['webhookToken' => $token]);

        if (!$config instanceof Beds24Config) {
            throw new RuntimeException("Token de webhook inválido o configuración no encontrada.");
        }

        if (!$config->isActivo()) {
            throw new RuntimeException("La configuración '{$config->getNombre()}' está inactiva.");
        }

        // 2. Ejecutar dentro del Contexto PULL
        $scope = $this->syncContext->enter(SyncContext::MODE_PULL, 'beds24');

        $conn = $this->em->getConnection();
        $conn->beginTransaction();

        try {
            // 3. Convertir a DTO
            $dto = Beds24BookingDto::fromArray($bookingData);

            // 4. Persistencia (Upsert)
            $this->persister->upsert($config, $dto);

            // 5. Commit
            $this->em->flush();
            $conn->commit();

            return ['success' => true, 'id' => $dto->id];

        } catch (Throwable $e) {
            $conn->rollBack();
            // Relanzamos la excepción para que el Controller la registre en el array de errores
            throw $e;
        } finally {
            $scope->restore();
        }
    }
}