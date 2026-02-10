<?php
declare(strict_types=1);

namespace App\Exchange\Service\Engine;

use App\Exchange\Service\Common\ExchangeTaskLocator;
use App\Exchange\Service\Common\HomogeneousBatch;
use App\Exchange\Service\Context\SyncContext;
use App\Exchange\Service\Contract\ExchangeQueueItemInterface;
use App\Pms\Service\Exchange\Persister\Beds24BookingPersister;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final class ExchangeOrchestrator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ExchangeBatchProcessor $batchProcessor, // <--- Usamos el nuevo procesador
        private readonly ExchangeTaskLocator    $taskLocator,
        private readonly SyncContext            $syncContext,
        private readonly LoggerInterface        $logger
    ) {}

    public function run(string $taskName, int $requestedLimit = 50): void
    {
        $task = $this->taskLocator->get($taskName);
        $workerId = gethostname() . '-' . getmypid();
        $now = new DateTimeImmutable();

        // --- LÓGICA DE LÍMITE INTELIGENTE ---
        // 1. Obtenemos el límite físico de la tarea (ej: 1 para Pull, 50 para Push)
        $hardLimit = $task->getMaxBatchSize();

        // 2. Calculamos el límite efectivo.
        // Si por consola pides 50, pero la tarea dice max 1, usamos 1.
        // Si por consola pides 10, y la tarea dice max 50, usamos 10 (respetamos tu deseo de ir lento).
        $effectiveLimit = ($requestedLimit > 0) ? min($requestedLimit, $hardLimit) : $hardLimit;

        // 3. OBTENER LOTE
        $batch = $task->getQueueProvider()->claimBatch($effectiveLimit, $workerId, $now);

        if (!$batch) {
            return; // Nada que procesar
        }

        // 2. ACTIVAR CONTEXTO DE SINCRONIZACIÓN
        $scope = $this->syncContext->enter(
            $task->getSyncMode(),
            $task->getSyncProvider()
        );

        try {
            // Protección de EM cerrado
            if (!$this->em->isOpen()) return;

            $this->em->beginTransaction();

            try {
                // 3. EJECUCIÓN DEL LOTE (I/O Red)
                // Obtenemos un array de ItemResult indexado por ID
                $results = $this->batchProcessor->processBatch($task, $batch);

                // 4. PROCESAMIENTO DE RESULTADOS (I/O Base de Datos)
                foreach ($batch->getItems() as $item) {
                    $itemId = (string) $item->getId();

                    // Buscamos el resultado específico para este ítem
                    $result = $results[$itemId] ?? null;

                    if ($result && $result->success) {
                        // ÉXITO: Delegamos al Handler específico de la tarea
                        $summary = $task->getHandler()->handleSuccess($result->extraData, $item);

                        // Actualizamos el execution_result con lo que devolvió el handler
                        $item->setExecutionResult($summary);

                    } else {
                        // FALLO LÓGICO (API respondió, pero dijo "error" para este ítem)
                        $msg = $result?->message ?? 'Error desconocido en respuesta batch';
                        // Simulamos una excepción para reusar lógica de fallo
                        $task->getHandler()->handleFailure(new \RuntimeException($msg), $item);
                    }
                }

                // 5. COMMIT DE LA TRANSACCIÓN
                // Aquí se disparan los Listeners (protegidos por SyncContext)
                $this->em->flush();
                $this->em->commit();

            } catch (Throwable $e) {
                // FALLO CATASTRÓFICO DEL LOTE (Ej: API caída, Timeout, Error PHP)
                if ($this->em->getConnection()->isTransactionActive()) {
                    $this->em->rollBack();
                }

                $this->handleCatastrophicBatchFailure($batch, $e);
            } finally {

            }

            // Limpiamos memoria de Doctrine para evitar fugas en procesos largos
            $this->em->clear();

        } finally {
            $scope->restore();
        }
    }

    /**
     * Si el proceso del lote explota (ej: 500 Server Error), marcamos TODOS los items como fallidos.
     */
    private function handleCatastrophicBatchFailure(HomogeneousBatch $batch, Throwable $e): void
    {
        $this->logger->error("Fallo Catastrófico en Batch Exchange: " . $e->getMessage(), [
            'trace' => $e->getTraceAsString()
        ]);

        $reason = mb_substr("Batch Error: " . $e->getMessage(), 0, 255);
        $retryAt = new DateTimeImmutable('+5 minutes');
        $code = (int)$e->getCode() ?: 500;

        // Intentamos guardar el fallo en DB vía SQL directo para no depender del EntityManager
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
        // Obtenemos el nombre de la tabla desde la entidad
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