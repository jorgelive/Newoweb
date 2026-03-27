<?php

declare(strict_types=1);

namespace App\Exchange\Service\Engine;

use App\Exchange\Service\Common\ExchangeTaskLocator;
use App\Exchange\Service\Common\HomogeneousBatch;
use App\Exchange\Service\Context\SyncContext;
use App\Exchange\Service\Contract\ExchangeQueueItemInterface;
use App\Exchange\Service\Contract\MemoryCleanableInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Motor central de intercambio.
 * Orquesta la ejecución de tareas batch de forma agnóstica al dominio.
 */
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
     * Ejecuta una tarea de intercambio (Pull/Push/etc).
     */
    public function run(string $taskName, int $requestedLimit = 50, array $specificIds = []): void
    {
        $task = $this->taskLocator->get($taskName);

        // Generamos un ID de worker distinto si es manual para facilitar debug en logs
        $isManual = !empty($specificIds);
        $workerId = gethostname() . '-' . ($isManual ? 'manual-' : '') . getmypid();
        $now = new DateTimeImmutable();

        // 1. Obtener Lote (Claim Check)
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
            return;
        }

        // 2. Activar Contexto de Sincronización (Modo y Proveedor)
        $scope = $this->syncContext->enter(
            $task->getSyncMode(),
            $task->getSyncProvider()
        );

        try {
            // Protección: Si el EntityManager se cerró en un proceso anterior, no podemos continuar.
            if (!$this->em->isOpen()) {
                $this->logger->emergency("EntityManager cerrado detectado en Orchestrator para: $taskName");
                return;
            }

            $this->em->beginTransaction();

            try {
                // 3. Procesamiento de Red (HttpClient + Mapping)
                $results = $this->batchProcessor->processBatch($task, $batch);

                // 4. Persistencia y Transición de Estados
                foreach ($batch->getItems() as $item) {
                    $itemId = (string) $item->getId();
                    $result = $results[$itemId] ?? null;

                    if ($result && $result->success) {
                        // El Handler de dominio (Beds24, Meta, etc) procesa el éxito
                        $summary = $task->getHandler()->handleSuccess($result->extraData, $item);
                        $item->setExecutionResult($summary);
                    } else {
                        // El Handler procesa el fallo lógico
                        $msg = $result?->message ?? 'Error desconocido en respuesta batch';
                        $task->getHandler()->handleFailure(new \RuntimeException($msg), $item);
                    }
                }

                $this->em->flush();
                $this->em->commit();

            } catch (Throwable $e) {
                // Rollback de base de datos ante errores graves
                if ($this->em->getConnection()->isTransactionActive()) {
                    $this->em->rollBack();
                }

                // Registro de fallo catastrófico (vía SQL nativo para evitar depender del estado del EM)
                $this->handleCatastrophicBatchFailure($batch, $e);

                // 🔥 IMPORTANTE: Relanzamos para que Symfony Messenger gestione el reintento/transporte de fallos
                throw $e;

            } finally {
                // 🔥 LIMPIEZA DE MEMORIA SELECTIVA (Agnóstica)
                // Esto sustituye al destructivo $em->clear()
                foreach ($batch->getItems() as $item) {

                    // Si el ítem implementa MemoryCleanableInterface, desvinculamos sus relaciones pesadas
                    if ($item instanceof MemoryCleanableInterface) {
                        foreach ($item->getRelatedEntitiesToDetach() as $relatedEntity) {
                            if ($relatedEntity && $this->em->contains($relatedEntity)) {
                                $this->em->detach($relatedEntity);
                            }
                        }
                    }

                    // Siempre desvinculamos el ítem de la cola para liberar la RAM del worker
                    if ($this->em->contains($item)) {
                        $this->em->detach($item);
                    }
                }
            }

        } finally {
            $scope->restore();
        }
    }

    /**
     * Gestiona fallos que impidieron completar la transacción (ej: Timeouts, Excepciones de Red).
     */
    private function handleCatastrophicBatchFailure(HomogeneousBatch $batch, Throwable $e): void
    {
        $this->logger->error("Fallo Catastrófico en Batch Exchange: " . $e->getMessage(), [
            'trace' => $e->getTraceAsString()
        ]);

        $reason = mb_substr("Catastrophic Error: " . $e->getMessage(), 0, 255);
        $retryAt = new DateTimeImmutable('+5 minutes');
        $code = (int)$e->getCode() ?: 500;

        try {
            foreach ($batch->getItems() as $item) {
                $this->saveDirectSqlFailure($item, $reason, $code, $retryAt);
            }
        } catch (Throwable $critical) {
            $this->logger->emergency("Incapaz de guardar estado de fallo catastrófico: " . $critical->getMessage());
        }
    }

    /**
     * Actualización forzada vía SQL para no depender de UnitOfWork de Doctrine.
     */
    private function saveDirectSqlFailure(ExchangeQueueItemInterface $item, string $reason, int $code, DateTimeImmutable $retryAt): void
    {
        $meta = $this->em->getClassMetadata(get_class($item));
        $table = $meta->getTableName();

        $binaryId = \Symfony\Component\Uid\Uuid::fromString((string) $item->getId())->toBinary();

        $affected = $this->em->getConnection()->executeStatement(
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
                'id'      => $binaryId,
            ],
            [
                'id' => \Doctrine\DBAL\ParameterType::BINARY,
            ]
        );

        if ($affected === 0) {
            $this->logger->error('saveDirectSqlFailure afectó 0 filas', [
                'id'    => (string) $item->getId(),
                'table' => $table,
            ]);
        }
    }
}