<?php
declare(strict_types=1);

namespace App\Pms\Repository;

use App\Pms\Entity\PmsTarifaQueueDelivery;
use DateInterval;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

final class PmsTarifaQueueDeliveryRepository extends ServiceEntityRepository
{
    private Connection $conn;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PmsTarifaQueueDelivery::class);
        $this->conn = $this->getEntityManager()->getConnection();
    }

    /**
     * DEBUG ONLY
     * Helper para inspección rápida (copiar/pegar SQL).
     * ⚠️ No ejecutar este SQL interpolado automáticamente.
     */
    private function buildDebugSql(string $sql, array $params): string
    {
        foreach ($params as $key => $value) {
            $placeholder = ':' . $key;

            if (is_array($value)) {
                $escaped = array_map(static function ($v) {
                    return is_numeric($v) ? $v : "'" . addslashes((string) $v) . "'";
                }, $value);

                $replacement = '(' . implode(', ', $escaped) . ')';
            } else {
                if ($value === null) {
                    $replacement = 'NULL';
                } elseif (is_numeric($value)) {
                    $replacement = (string) $value;
                } else {
                    $replacement = "'" . addslashes((string) $value) . "'";
                }
            }

            $sql = preg_replace(
                '/' . preg_quote($placeholder, '/') . '\\b/',
                $replacement,
                $sql,
                1
            );
        }

        return $sql;
    }

    /**
     * Selecciona y BLOQUEA deliveries ejecutables (tarifas) de forma atómica.
     *
     * Reglas (candidatas):
     * - d.needs_sync = 1
     * - d.status IN (pending, failed)
     * - d.locked_at IS NULL
     * - d.next_retry_at IS NULL OR <= now
     * - q.needs_sync = 1
     * - ep.activo = 1
     * - ep.accion = 'CALENDAR_POST'
     *
     * Además:
     * - Watchdog: resetea deliveries "zombis" en processing más viejos que TTL.
     *
     * @return PmsTarifaQueueDelivery[]
     */
    public function claimRunnableForRatesPush(
        int $limit,
        string $workerId,
        DateTimeImmutable $now,
        int $processingTtlSeconds = 90
    ): array {
        $nowStr = $now->format('Y-m-d H:i:s');

        // ------------------------------------------------------------------
        // 🐶 Watchdog: reset de deliveries "pegados" en processing
        // ------------------------------------------------------------------
        $cutoff = $now->sub(new DateInterval('PT' . max(1, $processingTtlSeconds) . 'S'));
        $cutoffStr = $cutoff->format('Y-m-d H:i:s');

        $this->conn->executeStatement(
            <<<SQL
            UPDATE pms_tarifa_queue_delivery
            SET
                status = 'failed',
                failed_reason = 'watchdog_timeout',
                last_message = LEFT(
                    CONCAT(
                        COALESCE(last_message, ''),
                        '\n[watchdog] reset processing older than TTL'
                    ),
                    255
                ),
                next_retry_at = :now,
                processing_started_at = NULL,
                locked_at = NULL,
                locked_by = NULL
            WHERE
                needs_sync = 1
                AND status = 'processing'
                AND processing_started_at IS NOT NULL
                AND processing_started_at <= :cutoff
            SQL,
            [
                'now' => $nowStr,
                'cutoff' => $cutoffStr,
            ],
            [
                'now' => \PDO::PARAM_STR,
                'cutoff' => \PDO::PARAM_STR,
            ]
        );

        // ------------------------------------------------------------------
        // 1) Pick IDs candidatos (sin lock todavía)
        //
        // Orden:
        // - beds24_config_id => batching por credenciales
        // - endpoint_id      => batching por endpoint
        // - effective_at     => orden lógico del cambio (tu “marca” correcta)
        // - id               => estabilidad
        // ------------------------------------------------------------------
        $ids = $this->conn->fetchFirstColumn(
            <<<SQL
            SELECT d.id
            FROM pms_tarifa_queue_delivery d
            INNER JOIN pms_tarifa_queue q ON q.id = d.pms_tarifa_queue_id
            INNER JOIN pms_beds24_endpoint ep ON ep.id = q.endpoint_id
            WHERE
                d.needs_sync = 1
                AND d.status IN ('pending', 'failed')
                AND d.locked_at IS NULL
                AND (d.next_retry_at IS NULL OR d.next_retry_at <= :now)
                AND q.needs_sync = 1
                AND ep.activo = 1
                AND ep.accion = :accion
            ORDER BY d.beds24_config_id ASC, q.endpoint_id ASC, d.effective_at ASC, d.id ASC
            LIMIT :limit
            SQL,
            [
                'now' => $nowStr,
                'accion' => 'CALENDAR_POST',
                'limit' => $limit,
            ],
            [
                'now' => \PDO::PARAM_STR,
                'accion' => \PDO::PARAM_STR,
                'limit' => \PDO::PARAM_INT,
            ]
        );

        if ($ids === []) {
            return [];
        }

        // ------------------------------------------------------------------
        // 2) Lock atómico
        // ------------------------------------------------------------------
        $this->conn->executeStatement(
            <<<SQL
            UPDATE pms_tarifa_queue_delivery
            SET
                locked_at = :now,
                locked_by = :workerId,
                status = 'processing',
                processing_started_at = :now
            WHERE
                id IN (:ids)
                AND locked_at IS NULL
            SQL,
            [
                'now' => $nowStr,
                'workerId' => $workerId,
                'ids' => $ids,
            ],
            [
                'now' => \PDO::PARAM_STR,
                'workerId' => \PDO::PARAM_STR,
                'ids' => ArrayParameterType::INTEGER,
            ]
        );

        // ------------------------------------------------------------------
        // 3) Re-hidratación ORM (fetch-join del grafo necesario)
        //
        // MISMA REGLA QUE TU LINK QUEUE:
        // NO filtramos por lockedBy aquí (IdentityMap puede mentir).
        // ------------------------------------------------------------------
        $qb = $this->createQueryBuilder('d')
            ->addSelect('q', 'ep', 'm', 'cfg', 'u', 'rango')
            ->leftJoin('d.queue', 'q')
            ->leftJoin('q.endpoint', 'ep')
            ->leftJoin('d.unidadBeds24Map', 'm')
            ->leftJoin('d.beds24Config', 'cfg')
            ->leftJoin('q.unidad', 'u')
            ->leftJoin('q.tarifaRango', 'rango')
            ->andWhere('d.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER);

        $query = $qb->getQuery();

        // Debug (breakpoints / profiler)
        $debugDql = $query->getDQL();
        $debugSql = $query->getSQL();

        $debugParams = [];
        foreach ($query->getParameters()->toArray() as $param) {
            $debugParams[$param->getName()] = $param->getValue();
        }

        $debugSqlFinal = $this->buildDebugSql($debugSql, $debugParams);

        $debugSqlMinimal = sprintf(
            "SELECT id, beds24_config_id, locked_by, status, needs_sync, next_retry_at, locked_at, processing_started_at
             FROM pms_tarifa_queue_delivery
             WHERE id IN (%s);",
            implode(', ', array_map('intval', $ids))
        );

        return $query->getResult();
    }
}