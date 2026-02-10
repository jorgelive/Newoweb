<?php
declare(strict_types=1);

namespace App\Pms\Repository;

use App\Exchange\Service\Contract\ExchangeQueueItemInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType; // ✅ Importante
use Doctrine\DBAL\ArrayParameterType; // ✅ Importante

/**
 * @template T of ExchangeQueueItemInterface
 * @extends ServiceEntityRepository<T>
 */
abstract class AbstractExchangeRepository extends ServiceEntityRepository
{
    abstract protected function getTableName(): string;

    /**
     * Reclama un lote de ítems HOMOGÉNEOS para procesar.
     */
    public function claimRunnable(int $limit, string $workerId, \DateTimeImmutable $now, int $ttl = 90): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $table = $this->getTableName();
        $nowSql = $now->format('Y-m-d H:i:s');

        // 1. WATCHDOG
        $expiredSql = $now->modify("-{$ttl} seconds")->format('Y-m-d H:i:s');
        $conn->executeStatement(
            "UPDATE {$table} SET status = 'failed', failed_reason = 'watchdog_timeout', locked_at = NULL, locked_by = NULL 
             WHERE locked_at IS NOT NULL AND locked_at <= :expired",
            ['expired' => $expiredSql]
        );

        // 2. PROBE
        // Recuperamos los IDs binarios crudos. PHP los maneja bien como strings binarios.
        $sqlProbe = "SELECT beds24_config_id, endpoint_id 
                     FROM {$table} 
                     WHERE status IN ('pending', 'failed')
                     AND retry_count < max_attempts
                     AND locked_at IS NULL
                     AND (run_at IS NULL OR run_at <= :now)
                     LIMIT 1";

        $context = $conn->fetchAssociative($sqlProbe, ['now' => $nowSql]);

        if (!$context) {
            return [];
        }

        // 3. FETCH HOMOGÉNEO (AQUÍ ESTABA EL ERROR)
        $sqlFetch = "SELECT id FROM {$table} 
                     WHERE status IN ('pending', 'failed')
                     AND retry_count < max_attempts
                     AND locked_at IS NULL
                     AND (run_at IS NULL OR run_at <= :now)
                     AND beds24_config_id = :cfgId
                     AND endpoint_id = :epId
                     ORDER BY run_at ASC, id ASC
                     LIMIT :limit 
                     FOR UPDATE SKIP LOCKED";

        // ✅ CORRECCIÓN: Definir explícitamente el tipo BINARY para los UUIDs
        $ids = $conn->fetchFirstColumn(
            $sqlFetch,
            [
                'now'   => $nowSql,
                'limit' => $limit,
                'cfgId' => $context['beds24_config_id'], // Valor binario crudo
                'epId'  => $context['endpoint_id']       // Valor binario crudo
            ],
            [
                'limit' => ParameterType::INTEGER,
                'cfgId' => ParameterType::BINARY, // ¡VITAL! Evita el error "Truncated incorrect DOUBLE"
                'epId'  => ParameterType::BINARY  // ¡VITAL!
            ]
        );

        if (empty($ids)) return [];

        // 4. LOCK
        // ✅ CORRECCIÓN AQUÍ: Usamos ArrayParameterType::STRING para UUIDs binarios
        $conn->executeStatement(
            "UPDATE {$table} SET status = 'processing', locked_at = :now, locked_by = :worker, retry_count = retry_count + 1 
             WHERE id IN (:ids)",
            [
                'now'    => $nowSql,
                'worker' => $workerId,
                'ids'    => $ids
            ],
            [
                'ids' => ArrayParameterType::STRING // <--- CAMBIO CRÍTICO (Antes era INTEGER)
            ]
        );

        return $this->hydrateItems($ids);
    }

    abstract protected function hydrateItems(array $ids): array;
}