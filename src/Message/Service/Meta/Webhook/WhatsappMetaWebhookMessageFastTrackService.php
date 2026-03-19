<?php

declare(strict_types=1);

namespace App\Message\Service\Meta\Webhook;

use App\Exchange\Entity\MetaConfig;
use App\Exchange\Service\Context\SyncContext;
use App\Message\Service\Exchange\Tasks\WhatsappMetaReceive\WhatsappMetaReceivePersister;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Throwable;

final class WhatsappMetaWebhookMessageFastTrackService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WhatsappMetaReceivePersister $persister,
        private readonly SyncContext $syncContext
    ) {}

    /**
     * Procesa UN solo mensaje entrante de un huésped.
     * @throws Throwable
     */
    public function processMessage(array $messageData, array $contactData): array
    {
        $this->validateConfig();

        $scope = $this->syncContext->enter(SyncContext::MODE_PULL, 'meta');
        $conn = $this->em->getConnection();
        $conn->beginTransaction();

        try {
            // Delegamos al Persister Agnóstico
            $this->persister->upsertInboundMessage($messageData, $contactData);

            $this->em->flush();
            $conn->commit();

            return ['success' => true, 'id' => $messageData['id'] ?? 'unknown'];

        } catch (Throwable $e) {
            $conn->rollBack();
            throw $e;
        } finally {
            $scope->restore();
        }
    }

    /**
     * Procesa UN solo cambio de estado (Enviado, Entregado, Leído, Fallido).
     * @throws Throwable
     */
    public function processStatus(array $statusData): array
    {
        $this->validateConfig();

        $scope = $this->syncContext->enter(SyncContext::MODE_PULL, 'meta');
        $conn = $this->em->getConnection();
        $conn->beginTransaction();

        try {
            // Delegamos al Persister Agnóstico
            $this->persister->updateMessageStatus($statusData);

            $this->em->flush();
            $conn->commit();

            return ['success' => true, 'id' => $statusData['id'] ?? 'unknown'];

        } catch (Throwable $e) {
            $conn->rollBack();
            throw $e;
        } finally {
            $scope->restore();
        }
    }

    /**
     * Valida que exista una configuración activa de Meta antes de procesar.
     */
    private function validateConfig(): void
    {
        $config = $this->em->getRepository(MetaConfig::class)->findOneBy(['activo' => true]);

        if (!$config instanceof MetaConfig) {
            throw new RuntimeException("No se encontró una configuración activa de Meta WhatsApp.");
        }
    }
}