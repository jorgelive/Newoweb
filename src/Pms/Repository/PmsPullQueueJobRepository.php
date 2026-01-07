<?php
declare(strict_types=1);

namespace App\Pms\Repository;

use App\Pms\Entity\PmsPullQueueJob;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class PmsPullQueueJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PmsPullQueueJob::class);
    }

    /**
     * Claimea 1 job de forma segura (MySQL 8):
     * - status = pending
     * - run_at <= now
     * - type = :type
     * - orden: priority ASC, run_at ASC
     *
     * Incluye watchdog:
     * - jobs RUNNING con locked_at viejo vuelven a PENDING (TTL).
     *
     * Importante:
     * - Este método usa DBAL + transacción para SELECT ... FOR UPDATE SKIP LOCKED.
     * - Devuelve la entidad ya MANAGED por Doctrine (via find()).
     */
    public function claimNextRunnable(
        string $type,
        string $workerId,
        DateTimeImmutable $now,
        int $processingTtlSeconds
    ): ?PmsPullQueueJob {
        $conn = $this->getEntityManager()->getConnection();

        // Normalizamos a string SQL (DBAL 3 no castea DateTime automáticamente en bindValue)
        $nowSql = $now->format('Y-m-d H:i:s');
        $expired = $now->modify('-' . max(30, $processingTtlSeconds) . ' seconds');
        $expiredSql = $expired->format('Y-m-d H:i:s');

        // 1) Watchdog: liberar locks viejos (running + locked_at < now-ttl)
        $conn->executeStatement(
            <<<SQL
            UPDATE pms_pull_queue_job
            SET status = :pending,
                locked_at = NULL,
                locked_by = NULL
            WHERE status = :running
              AND locked_at IS NOT NULL
              AND locked_at < :expired
            SQL,
            [
                'pending' => PmsPullQueueJob::STATUS_PENDING,
                'running' => PmsPullQueueJob::STATUS_RUNNING,
                'expired' => $expiredSql,
            ]
        );

        // 2) Claim atómico dentro de transacción
        $conn->beginTransaction();

        try {
            $id = $conn->fetchOne(
                <<<SQL
                SELECT id
                FROM pms_pull_queue_job
                WHERE status = :pending
                  AND run_at <= :now
                  AND type = :type
                ORDER BY priority ASC, run_at ASC
                LIMIT 1
                FOR UPDATE SKIP LOCKED
                SQL,
                [
                    'pending' => PmsPullQueueJob::STATUS_PENDING,
                    'now' => $nowSql,
                    'type' => $type,
                ]
            );

            if (!$id) {
                $conn->commit();
                return null;
            }

            // 3) Marcar RUNNING + lock + attempts++
            $conn->executeStatement(
                <<<SQL
                UPDATE pms_pull_queue_job
                SET status = :running,
                    locked_at = :now,
                    locked_by = :worker,
                    attempts = attempts + 1
                WHERE id = :id
                SQL,
                [
                    'running' => PmsPullQueueJob::STATUS_RUNNING,
                    'now' => $nowSql,
                    'worker' => $workerId,
                    'id' => (int) $id,
                ]
            );

            $conn->commit();

            /** @var PmsPullQueueJob|null $job */
            $job = $this->find((int) $id);

            return $job instanceof PmsPullQueueJob ? $job : null;

        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }
}