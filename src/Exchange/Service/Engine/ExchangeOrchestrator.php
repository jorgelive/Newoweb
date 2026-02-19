<?php

declare(strict_types=1);

namespace App\Exchange\Service\Engine;

use App\Exchange\Service\Common\ExchangeTaskLocator;
use App\Exchange\Service\Common\HomogeneousBatch;
use App\Exchange\Service\Context\SyncContext;
use App\Exchange\Service\Contract\ExchangeQueueItemInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final class ExchangeOrchestrator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ExchangeBatchProcessor $batchProcessor,
        private readonly ExchangeTaskLocator    $taskLocator,
        private readonly SyncContext            $syncContext,
        private readonly LoggerInterface        $logger
    ) {}

    /**
     * @param string $taskName       Nombre de la tarea.
     * @param int    $requestedLimit Límite para modo Cron (ignorado si hay specificIds).
     * @param array  $specificIds    IDs para ejecución manual inmediata.
     */
    public function run(string $taskName, int $requestedLimit = 50, array $specificIds = []): void
    {
        $task = $this->taskLocator->get($taskName);

        // Generamos un ID de worker distinto si es manual para facilitar debug en logs
        $isManual = !empty($specificIds);
        $workerId = gethostname() . '-' . ($isManual ? 'manual-' : '') . getmypid();
        $now = new DateTimeImmutable();

        // --- 1. OBTENER LOTE (Claim Check) ---
        if ($isManual) {
            // MODO MANUAL: Pasamos los IDs específicos
            $batch = $task->getQueueProvider()->claimSpecificBatch($specificIds, $workerId, $now);
        } else {
            // MODO CRON: Calculamos límite efectivo
            $hardLimit = $task->getMaxBatchSize();
            $effectiveLimit = ($requestedLimit > 0) ? min($requestedLimit, $hardLimit) : $hardLimit;

            $batch = $task->getQueueProvider()->claimBatch($effectiveLimit, $workerId, $now);
        }

        if (!$batch) {
            return; // Nada que procesar
        }

        // --- 2. ACTIVAR CONTEXTO ---
        $scope = $this->syncContext->enter(
            $task->getSyncMode(),
            $task->getSyncProvider()
        );

        try {
            if (!$this->em->isOpen()) return;

            $this->em->beginTransaction();

            try {
                // --- 3. PROCESAMIENTO (Red) ---
                $results = $this->batchProcessor->processBatch($task, $batch);

                // --- 4. PERSISTENCIA (BD) ---
                foreach ($batch->getItems() as $item) {
                    $itemId = (string) $item->getId();
                    $result = $results[$itemId] ?? null;

                    if ($result && $result->success) {
                        $summary = $task->getHandler()->handleSuccess($result->extraData, $item);
                        $item->setExecutionResult($summary);
                    } else {
                        $msg = $result?->message ?? 'Error desconocido en respuesta batch';
                        $task->getHandler()->handleFailure(new \RuntimeException($msg), $item);
                    }
                }

                $this->em->flush();
                $this->em->commit();

            } catch (Throwable $e) {
                if ($this->em->getConnection()->isTransactionActive()) {
                    $this->em->rollBack();
                }
                $this->handleCatastrophicBatchFailure($batch, $e);
            } finally {
                $this->em->clear();
            }

        } finally {
            $scope->restore();
        }
    }

    private function handleCatastrophicBatchFailure(HomogeneousBatch $batch, Throwable $e): void
    {
        $this->logger->error("Fallo Catastrófico en Batch Exchange: " . $e->getMessage(), [
            'trace' => $e->getTraceAsString()
        ]);

        $reason = mb_substr("Batch Error: " . $e->getMessage(), 0, 255);
        $retryAt = new DateTimeImmutable('+5 minutes');
        $code = (int)$e->getCode() ?: 500;

        try {
            foreach ($batch->getItems() as $item) {
                $this->saveDirectSqlFailure($item, $reason, $code, $retryAt);
            }
        } catch (Throwable $critical) {
            $this->logger->emergency("No se pudo guardar el estado de fallo catastrófico: " . $critical->getMessage());
        }
    }

    private function saveDirectSqlFailure(ExchangeQueueItemInterface $item, string $reason, int $code, DateTimeImmutable $retryAt): void
    {
        $meta = $this->em->getClassMetadata(get_class($item));
        $table = $meta->getTableName();

        $this->em->getConnection()->executeStatement(
            "UPDATE {$table} SET 
                status = 'failed', 
                failed_reason = :reason, 
                last_http_code = :code,
                run_at = :retryAt, 
                locked_at = NULL, 
                locked_by = NULL,
                retry_count = retry_count + 1
             WHERE id = :id",
            [
                'reason'  => $reason,
                'code'    => $code,
                'retryAt' => $retryAt->format('Y-m-d H:i:s'),
                'id'      => $item->getId()
            ]
        );
    }
}