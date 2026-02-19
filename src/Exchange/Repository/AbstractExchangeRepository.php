<?php

declare(strict_types=1);

namespace App\Exchange\Repository;

use App\Exchange\Service\Contract\ExchangeQueueItemInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * @template T of ExchangeQueueItemInterface
 * @extends ServiceEntityRepository<T>
 * * Repositorio Base Abstracto para Colas de Intercambio (Exchange).
 * Maneja la lógica de bajo nivel de bloqueos (Pessimistic Locking) transaccionales,
 * conversión de binarios UUID y limpieza de procesos zombies.
 */
abstract class AbstractExchangeRepository extends ServiceEntityRepository
{
    abstract protected function getTableName(): string;

    /**
     * Hidrata los objetos completos de Doctrine a partir de IDs binarios.
     * @param string[] $ids Array de IDs en formato BINARIO (16 bytes)
     */
    abstract protected function hydrateItems(array $ids): array;

    /**
     * ✅ NORMALIZADOR ESTÁNDAR (UUID v7 / v4).
     * * Convierte strings UUID (con o sin guiones) a binario de 16 bytes.
     * * NOTA IMPORTANTE SOBRE UUID v7:
     * Al usar UUID v7, el componente de tiempo ya está al principio del string ("019c...").
     * Por lo tanto, NO debemos reordenar bytes (byte-swapping) como se hacía con UUID v1
     * en implementaciones antiguas de Doctrine. La conversión es DIRECTA.
     * * @param string[] $ids Lista de UUIDs en texto.
     * @return string[] Lista de UUIDs en binario puro (16 bytes).
     */
    protected function normalizeToBinary(array $ids): array
    {
        $binaryIds = [];

        foreach ($ids as $id) {
            if (empty($id) || !is_string($id)) continue;

            try {
                // Uuid::fromString es robusto: acepta "019c6da1-..." y "019c6da1..."
                // toBinary() devuelve los 16 bytes crudos tal cual están en la DB.
                $binaryIds[] = Uuid::fromString($id)->toBinary();
            } catch (\Exception $e) {
                // Ignoramos IDs mal formados para no romper el proceso por un dato sucio.
                continue;
            }
        }

        // Eliminamos duplicados para optimizar la query SQL
        return array_unique($binaryIds);
    }

    /**
     * 🔄 MODO CRON (Worker Pasivo).
     * Reclama un lote de ítems HOMOGÉNEOS de forma transaccional y atómica.
     * * Estrategia "Probe & Fetch":
     * 1. Watchdog: Limpia bloqueos viejos (Fuera de transacción para evitar bloqueos masivos).
     * 2. Transaction Start: Asegura que el SELECT FOR UPDATE mantenga el bloqueo.
     * 3. Probe: Busca 1 candidato libre.
     * 4. Fetch: Busca N ítems con bloqueo FOR UPDATE.
     * 5. Lock: Ejecuta el UPDATE atómico.
     * 6. Transaction Commit.
     */
    public function claimRunnable(int $limit, string $workerId, \DateTimeImmutable $now, int $ttl = 90): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $table = $this->getTableName();
        $nowSql = $now->format('Y-m-d H:i:s');

        // 1. WATCHDOG GENERAL: Liberar locks zombies de cualquier proceso muerto.
        // Se hace fuera de la transacción principal para no penalizar el rendimiento.
        $expiredSql = $now->modify("-{$ttl} seconds")->format('Y-m-d H:i:s');
        $conn->executeStatement(
            "UPDATE {$table} SET status = 'failed', failed_reason = 'watchdog_timeout', locked_at = NULL, locked_by = NULL 
             WHERE locked_at IS NOT NULL AND locked_at <= :expired",
            ['expired' => $expiredSql]
        );

        // ABRIMOS TRANSACCIÓN PARA ASEGURAR EL 'FOR UPDATE SKIP LOCKED'
        $conn->beginTransaction();

        try {
            // 2. PROBE (Sonda): Buscar un candidato viable.
            // No necesita FOR UPDATE porque solo estamos leyendo metadatos (config/endpoint).
            $sqlProbe = "SELECT config_id, endpoint_id 
                         FROM {$table} 
                         WHERE status IN ('pending', 'failed')
                         AND retry_count < max_attempts
                         AND locked_at IS NULL
                         AND (run_at IS NULL OR run_at <= :now)
                         LIMIT 1";

            $context = $conn->fetchAssociative($sqlProbe, ['now' => $nowSql]);

            if (!$context) {
                $conn->commit();
                return []; // Nada que procesar
            }

            // 3. FETCH HOMOGÉNEO: Traer lote que coincida con la sonda.
            // Al estar dentro de beginTransaction(), el 'FOR UPDATE' bloquea físicamente
            // estas filas contra otros SELECT FOR UPDATE de otros workers concurrentes.
            $sqlFetch = "SELECT id FROM {$table} 
                         WHERE status IN ('pending', 'failed')
                         AND retry_count < max_attempts
                         AND locked_at IS NULL
                         AND (run_at IS NULL OR run_at <= :now)
                         AND config_id = :cfgId
                         AND endpoint_id = :epId
                         ORDER BY run_at ASC, id ASC
                         LIMIT :limit 
                         FOR UPDATE SKIP LOCKED";

            $ids = $conn->fetchFirstColumn(
                $sqlFetch,
                [
                    'now'   => $nowSql,
                    'limit' => $limit,
                    'cfgId' => $context['config_id'],
                    'epId'  => $context['endpoint_id']
                ],
                [
                    'limit' => ParameterType::INTEGER,
                    'cfgId' => ParameterType::BINARY,
                    'epId'  => ParameterType::BINARY
                ]
            );

            if (empty($ids)) {
                $conn->commit();
                return [];
            }

            // 4. LOCK: Marcar como procesando (UPDATE)
            $this->lockItems($ids, $workerId, $nowSql, $table);

            // 5. COMMIT: Las filas quedan con su status='processing' y se libera el bloqueo del SELECT.
            $conn->commit();

            // 6. HYDRATE: Devolver objetos Doctrine
            $items = $this->hydrateItems($ids);

            // 7. 🛠️ EL FIX: SINCRONIZAR DOCTRINE
            // Como bloqueamos por SQL directo (DBAL), Doctrine no sabe que los registros cambiaron.
            // Forzamos a que lea la BD para que su memoria coincida con la realidad.
            foreach ($items as $item) {
                $this->getEntityManager()->refresh($item);
            }

            return $items;

        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    /**
     * ⚡ MODO MANUAL / REAL-TIME.
     * Reclama IDs específicos solicitados por el usuario o un evento de forma transaccional.
     */
    public function claimSpecificItems(array $ids, string $workerId, \DateTimeImmutable $now, int $ttl = 300): array
    {
        // 1. NORMALIZACIÓN DIRECTA (UUID v7 -> Binario 16 bytes)
        $binaryIds = $this->normalizeToBinary($ids);

        if (empty($binaryIds)) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();
        $table = $this->getTableName();
        $nowSql = $now->format('Y-m-d H:i:s');

        // 2. 🐶 WATCHDOG FOCALIZADO (CRÍTICO) - Fuera de la transacción de bloqueo
        $expiredSql = $now->modify("-{$ttl} seconds")->format('Y-m-d H:i:s');
        $conn->executeStatement(
            "UPDATE {$table} 
             SET status = 'pending', locked_at = NULL, locked_by = NULL 
             WHERE id IN (:ids) AND locked_at <= :expired",
            ['ids' => $binaryIds, 'expired' => $expiredSql],
            ['ids' => ArrayParameterType::BINARY]
        );

        // ABRIMOS TRANSACCIÓN PARA ASEGURAR EL 'FOR UPDATE SKIP LOCKED'
        $conn->beginTransaction();

        try {
            // 3. SELECCIÓN ATÓMICA
            // El 'FOR UPDATE' ahora sí retiene el bloqueo en la BD hasta el commit.
            $sqlCheck = "SELECT id FROM {$table}
                         WHERE id IN (:ids)
                         AND status IN ('pending', 'failed')
                         AND locked_at IS NULL
                         FOR UPDATE SKIP LOCKED";

            $availableBinaryIds = $conn->fetchFirstColumn(
                $sqlCheck,
                ['ids' => $binaryIds],
                ['ids' => ArrayParameterType::BINARY]
            );

            if (empty($availableBinaryIds)) {
                $conn->commit();
                return [];
            }

            // 4. LOCK & COMMIT
            $this->lockItems($availableBinaryIds, $workerId, $nowSql, $table);
            $conn->commit();

            // 5. HYDRATE
            $items = $this->hydrateItems($availableBinaryIds);

            // 6. 🛠️ EL FIX: SINCRONIZAR DOCTRINE
            // Como bloqueamos por SQL directo (DBAL), Doctrine no sabe que los registros cambiaron.
            // Forzamos a que lea la BD para que su memoria coincida con la realidad.
            foreach ($items as $item) {
                $this->getEntityManager()->refresh($item);
            }

            return $items;

        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    /**
     * Helper privado para aplicar el bloqueo (UPDATE) masivo.
     * Incrementa retry_count y asigna el worker.
     * Nota: Este método asume que se llama DENTRO de una transacción activa.
     */
    private function lockItems(array $binaryIds, string $workerId, string $nowSql, string $table): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            "UPDATE {$table} SET status = 'processing', locked_at = :now, locked_by = :worker, retry_count = retry_count + 1 
             WHERE id IN (:ids)",
            [
                'now'    => $nowSql,
                'worker' => $workerId,
                'ids'    => $binaryIds
            ],
            ['ids' => ArrayParameterType::BINARY]
        );
    }

    /**
     * Obtiene metadatos ligeros para agrupación (Pre-Sorting) en el MessageHandler.
     * Devuelve los IDs y Configs en formato TEXTO (UUID con guiones) para PHP.
     *
     * @return array<string, array{config_id: string, endpoint_id: string}>
     */
    public function getGroupingMetadata(array $ids): array
    {
        // 1. Convertir entrada a binario para buscar en DB
        $binaryIds = $this->normalizeToBinary($ids);

        if (empty($binaryIds)) return [];

        $conn = $this->getEntityManager()->getConnection();
        $table = $this->getTableName();

        // 2. Pedir salida en STRING (BIN_TO_UUID)
        $sql = "SELECT 
            BIN_TO_UUID(id) as id, 
            BIN_TO_UUID(config_id) as config_id, 
            BIN_TO_UUID(endpoint_id) as endpoint_id 
        FROM {$table} 
        WHERE id IN (:binaryIds)";

        $rows = $conn->fetchAllAssociative(
            $sql,
            ['binaryIds' => $binaryIds],
            ['binaryIds' => ArrayParameterType::BINARY]
        );

        // 3. Mapear resultado indexado por ID (texto)
        $result = [];
        foreach ($rows as $row) {
            $result[$row['id']] = [
                'config_id'   => $row['config_id'],
                'endpoint_id' => $row['endpoint_id']
            ];
        }

        return $result;
    }
}