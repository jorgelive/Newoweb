<?php
declare(strict_types=1);

namespace App\Message\Service\Beds24\Webhook;

use App\Exchange\Entity\Beds24Config;
use App\Exchange\Service\Context\SyncContext;
use App\Message\Dto\Beds24MessageDto;
use App\Message\Service\Exchange\Tasks\Beds24Receive\Beds24ReceivePersister;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Throwable;

final readonly class Beds24WebhookMessageFastTrackService
{
    public function __construct(
        private EntityManagerInterface $em,
        private Beds24ReceivePersister $persister,
        private SyncContext            $syncContext,
    ) {}

    /**
     * Procesa UN solo mensaje proveniente del webhook.
     * @throws Throwable Si algo falla, el Controller captura y loguea.
     */
    public function process(string $token, array $messageData): array
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
            $dto = Beds24MessageDto::fromArray($messageData);

            if (empty($dto->bookingId)) {
                throw new RuntimeException("El mensaje (ID: {$dto->id}) no tiene un bookingId asociado.");
            }

            // 4. Persistencia (Upsert)
            // El persister espera un array de mensajes, le pasamos este único DTO en un array
            $stats = $this->persister->upsertMessages((string) $dto->bookingId, [$dto]);

            // 5. Commit
            $this->em->flush();
            $conn->commit();

            return [
                'success' => true,
                'id' => $dto->id,
                'stats' => $stats
            ];

        } catch (Throwable $e) {
            $conn->rollBack();
            // Relanzamos la excepción para que el Controller la registre en el array de errores
            throw $e;
        } finally {
            $scope->restore();
        }
    }
}