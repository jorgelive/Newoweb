<?php
declare(strict_types=1);

namespace App\Pms\Repository;

use App\Pms\Entity\PmsBeds24LinkQueue;
use DateInterval;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

final class PmsBeds24LinkQueueRepository extends ServiceEntityRepository
{
    private Connection $conn;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PmsBeds24LinkQueue::class);
        $this->conn = $this->getEntityManager()->getConnection();
    }

    /**
     * DEBUG ONLY
     *
     * Construye un SQL final con parámetros interpolados,
     * útil para copiar/pegar directamente en el cliente SQL.
     *
     * ⚠️ No usar este SQL para ejecución automática.
     *
     * Nota importante:
     * - Doctrine ORM a veces genera placeholders posicionales (?) en getSQL().
     *   Este helper está pensado principalmente para placeholders nombrados (:foo).
     * - Si tu SQL tiene "?", este interpolador NO podrá reemplazarlo correctamente.
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
     * Selecciona y BLOQUEA colas ejecutables de forma atómica.
     *
     * Reglas (candidatas):
     * - needs_sync = 1
     * - status IN (pending, failed)
     * - locked_at IS NULL
     * - next_retry_at IS NULL OR <= now
     *
     * Además:
     * - Watchdog: resetea colas "zombis" en processing más viejas que un TTL.
     *
     * @return PmsBeds24LinkQueue[]
     */
    public function claimRunnable(
        int $limit,
        string $workerId,
        DateTimeImmutable $now,
        int $processingTtlSeconds = 90
    ): array {
        $nowStr = $now->format('Y-m-d H:i:s');

        // ------------------------------------------------------------------
        // 🐶 Watchdog (soft):
        // Si un worker muere/crashea, algunas colas quedan "pegadas" en:
        //   status=processing + locked_at + locked_by + processing_started_at
        //
        // Estrategia:
        // - Usamos processing_started_at como reloj real para detectar zombis.
        // - locked_at/locked_by se consideran “lock administrativo” (auditoría),
        //   no el reloj del watchdog.
        // - Reset → las pasamos a failed, dejamos mensaje, y las reintentamos ya.
        //
        // IMPORTANTÍSIMO para debug:
        // - En producción puedes usar TTL 90s (o más).
        // - En debug puedes bajar TTL en el command/orchestrator si lo deseas.
        // ------------------------------------------------------------------
        $cutoff = $now->sub(new DateInterval('PT' . max(1, $processingTtlSeconds) . 'S'));
        $cutoffStr = $cutoff->format('Y-m-d H:i:s');

        $this->conn->executeStatement(
            <<<SQL
            UPDATE pms_beds24_link_queue
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
        // 1) Seleccionamos IDs candidatos (sin lock todavía)
        //
        // Nota:
        // - Ordenamos por config + endpoint + created para:
        //   - mejorar batching (menos requests)
        //   - fairness entre cuentas (si agregas más configs)
        // ------------------------------------------------------------------
        $ids = $this->conn->fetchFirstColumn(
            <<<SQL
            SELECT id
            FROM pms_beds24_link_queue
            WHERE
                needs_sync = 1
                AND status IN ('pending', 'failed')
                AND locked_at IS NULL
                AND (next_retry_at IS NULL OR next_retry_at <= :now)
            ORDER BY beds24_config_id ASC, endpoint_id ASC, created ASC
            LIMIT :limit
            SQL,
            [
                'now'   => $nowStr,
                'limit' => $limit,
            ],
            [
                'now'   => \PDO::PARAM_STR,
                'limit' => \PDO::PARAM_INT,
            ]
        );

        if ($ids === []) {
            return [];
        }

        // ------------------------------------------------------------------
        // 2) Lock atómico
        //
        // Nota de concurrencia:
        // - WHERE locked_at IS NULL evita "robar" locks.
        // - Usamos locked_at/locked_by para auditoría y diagnóstico.
        // - processing_started_at es el reloj del watchdog.
        // ------------------------------------------------------------------
        $this->conn->executeStatement(
            <<<SQL
            UPDATE pms_beds24_link_queue
            SET
                locked_at = :now,
                locked_by = :workerId,
                status    = 'processing',
                processing_started_at = :now
            WHERE
                id IN (:ids)
                AND locked_at IS NULL
            SQL,
            [
                'now'      => $nowStr,
                'workerId' => $workerId,
                'ids'      => $ids,
            ],
            [
                'now'      => \PDO::PARAM_STR,
                'workerId' => \PDO::PARAM_STR,
                'ids'      => ArrayParameterType::INTEGER,
            ]
        );

        // ------------------------------------------------------------------
        // 3) Re-hidratación ORM (fetch-join del grafo que necesita el processor)
        //
        // 🔥 IMPORTANTÍSIMO (horas de debug evitadas):
        // NO filtramos por lockedBy aquí.
        //
        // ¿Por qué?
        // - El lock real ya ocurrió en SQL (paso 2).
        // - Si Doctrine ya tenía estas entidades en el UnitOfWork (IdentityMap),
        //   puede NO ver los cambios hechos por DBAL (locked_by/status), y el filtro
        //   `q.lockedBy = :workerId` puede devolver 0 aunque la DB tenga datos correctos.
        //
        // Conclusión:
        // - Usamos SOLO los IDs que acabamos de lockear (son nuestra “prueba”).
        // - Esto evita inconsistencias ORM vs DB y elimina el “array[0] pero dice reclaimed 9”.
        // ------------------------------------------------------------------
        $qb = $this->createQueryBuilder('q')
            ->addSelect('ep', 'l', 'e', 'r', 'm')
            ->leftJoin('q.endpoint', 'ep')
            ->leftJoin('q.link', 'l')
            ->leftJoin('l.evento', 'e')
            ->leftJoin('e.reserva', 'r')
            ->leftJoin('l.unidadBeds24Map', 'm')
            ->andWhere('q.id IN (:ids)')
            // ⚠️ IMPORTANT: sin tipo, Doctrine puede bindear el array como un solo parámetro.
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER);

        $query = $qb->getQuery();

        // ------------------------------------------------------------------
        // DEBUG / INSPECCIÓN (solo para breakpoint / profiler)
        //
        // Estas variables existen para poder ver:
        // - DQL generado
        // - SQL generado
        // - parámetros
        // - SQL minimal copiable
        //
        // Nota:
        // - No “usamos” estas variables en runtime; son intencionalmente side-effect free.
        // - Si mañana quieres mandarlas a logger, ya están listas.
        // ------------------------------------------------------------------
        $debugDql = $query->getDQL();
        $debugSql = $query->getSQL();

        $debugParams = [];
        foreach ($query->getParameters()->toArray() as $param) {
            $debugParams[$param->getName()] = $param->getValue();
        }

        $debugSqlFinal = $this->buildDebugSql($debugSql, $debugParams);

        // SQL minimal, copiable y 100% confiable para verificar rápido en MySQL
        // (útil cuando Doctrine usa placeholders posicionales '?' y no se interpola).
        $debugSqlMinimal = sprintf(
            "SELECT id, locked_by, status, needs_sync, next_retry_at, locked_at, processing_started_at
             FROM pms_beds24_link_queue
             WHERE id IN (%s);",
            implode(', ', array_map('intval', $ids))
        );

        return $query->getResult();
    }
}