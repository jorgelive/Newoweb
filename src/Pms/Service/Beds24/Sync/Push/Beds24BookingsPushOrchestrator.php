<?php
declare(strict_types=1);

namespace App\Pms\Service\Beds24\Sync\Push;

use App\Pms\Repository\PmsBeds24LinkQueueRepository;
use App\Pms\Service\Beds24\Queue\Beds24LinkQueueProcessor;
use App\Pms\Service\Beds24\Sync\SyncContext;
use DateTimeImmutable;

/**
 * Orchestrator del PUSH hacia Beds24.
 *
 * Responsabilidades CLARAS:
 *  - Definir el CONTEXTO GLOBAL de ejecución (SOURCE_PUSH_BEDS24)
 *  - Reclamar colas de forma atómica (lock SQL)
 *  - Ejecutar el procesamiento batch
 *
 * ⚠️ Regla crítica de arquitectura:
 * Este es el ÚNICO lugar donde se entra en SOURCE_PUSH_BEDS24.
 * El listener Doctrine debe cortar inmediatamente si detecta este contexto,
 * o se producen loops infinitos de re-encolado.
 */
final class Beds24BookingsPushOrchestrator
{
    public function __construct(
        private readonly PmsBeds24LinkQueueRepository $queueRepo,
        private readonly Beds24LinkQueueProcessor $processor,
        private readonly SyncContext $syncContext,
    ) {}

    /**
     * Ejecuta una iteración del worker.
     *
     * - Es idempotente
     * - Es seguro para múltiples workers en paralelo
     * - No hace flush adicional fuera del processor
     */
    public function runOnce(int $limit = 50, ?string $workerId = null): int
    {
        $now = new DateTimeImmutable();
        $workerId = $workerId ?: ('cli-' . substr(sha1((string) microtime(true)), 0, 8));

        // 🔐 Entramos explícitamente en contexto PUSH
        // Todo flush posterior será considerado "push-originado"
        $scope = $this->syncContext->enterSource(SyncContext::SOURCE_PUSH_BEDS24);

        try {
            // 1) Claim atómico de colas ejecutables
            $queues = $this->queueRepo->claimRunnable($limit, $workerId, $now);
            if ($queues === []) {
                return 0;
            }

            // 2) Procesamiento real (batch por config + endpoint)
            return $this->processor->processBatch($queues, $now, $workerId);

        } finally {
            // 🔓 MUY IMPORTANTE:
            // Restaurar el contexto previo para evitar estado "pegajoso"
            $scope->restore();
        }
    }
}