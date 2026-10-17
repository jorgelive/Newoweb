<?php
declare(strict_types=1);

namespace App\Pms\Service\Beds24\Webhook;

use App\Exchange\Entity\Beds24Config;
use App\Exchange\Service\Context\SyncContext;
use App\Pms\Dto\Beds24BookingDto;
use App\Pms\Service\Exchange\Tasks\BookingsPull\BookingPullPersister;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Throwable;

final class Beds24WebhookBookingFastTrackService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BookingPullPersister   $persister,
        private readonly SyncContext            $syncContext,
    ) {}

    /**
     * Procesa UNA sola reserva.
     * @throws Throwable Si algo falla, el Controller captura y loguea.
     *
     * @param array<mixed> $bookingData Un booking de Beds24 tal cual; lo lee `Beds24BookingDto::fromArray()`.
     * @return array<string, mixed>
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

            // 🧹 **El rollback deshace la base, no la memoria.** (17/10/2026) Si `upsert()` lanzó
            // después de un `persist()` —un mapeo que falta, un link roto—, la reserva y el evento
            // a medio construir siguen agendados en el UnitOfWork. Quien llama atrapa el error y
            // SIGUE (mensajes, facturas, auditoría) hasta un `flush()` final, que los habría
            // insertado: una reserva sin estancia, que es justo lo que se encontró del 05/08/2026.
            //
            // Se vacía entero porque no hay forma fiable de separar lo de esta reserva de lo
            // demás. Lo que el llamante necesite después lo vuelve a leer: ver
            // `ProcessBeds24WebhookDispatchHandler`, que guarda la auditoría ANTES por esto.
            if ($this->em->isOpen()) {
                $this->em->clear();
            }
            $this->persister->reset();

            // Relanzamos la excepción para que el Controller la registre en el array de errores
            throw $e;
        } finally {
            $scope->restore();
        }
    }
}