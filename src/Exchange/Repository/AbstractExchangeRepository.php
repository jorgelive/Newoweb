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
 * Maneja la lógica de bajo nivel de bloqueos (Pessimistic Locking),
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
     * Reclama un lote de ítems HOMOGÉNEOS (misma config + endpoint).
     * * Estrategia "Probe & Fetch":
     * 1. Watchdog: Limpia bloqueos viejos globales.
     * 2. Probe: Busca 1 candidato libre para saber qué agrupar.
     * 3. Fetch: Busca N ítems que coincidan con ese candidato.
     * 4. Lock: Bloquea atómicamente.
     */
    public function claimRunnable(int $limit, string $workerId, \DateTimeImmutable $now, int $ttl = 90): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $table = $this->getTableName();
        $nowSql = $now->format('Y-m-d H:i:s');

        // 1. WATCHDOG GENERAL: Liberar locks zombies de cualquier proceso muerto.
        $expiredSql = $now->modify("-{$ttl} seconds")->format('Y-m-d H:i:s');
        $conn->executeStatement(
            "UPDATE {$table} SET status = 'failed', failed_reason = 'watchdog_timeout', locked_at = NULL, locked_by = NULL 
             WHERE locked_at IS NOT NULL AND locked_at <= :expired",
            ['expired' => $expiredSql]
        );

        // 2. PROBE (Sonda): Buscar un candidato viable.
        // Solo necesitamos config_id y endpoint_id para establecer el criterio de agrupación.
        $sqlProbe = "SELECT config_id, endpoint_id 
                     FROM {$table} 
                     WHERE status IN ('pending', 'failed')
                     AND retry_count < max_attempts
                     AND locked_at IS NULL
                     AND (run_at IS NULL OR run_at <= :now)
                     LIMIT 1";

        $context = $conn->fetchAssociative($sqlProbe, ['now' => $nowSql]);

        if (!$context) {
            return []; // Nada que procesar
        }

        // 3. FETCH HOMOGÉNEO: Traer lote que coincida con la sonda.
        // Usamos ParameterType::BINARY explícitamente.
        $sqlFetch = "SELECT id FROM {$table} 
                     WHERE status IN ('pending', 'failed')
                     AND retry_count < max_attempts
                     AND locked_at IS NULL
                     AND (run_at IS NULL OR run_at <= :now)
                     AND config_id = :cfgId
                     AND endpoint_id = :epId
                     ORDER BY run_at ASC, id ASC
                     LIMIT :limit 
                     FOR UPDATE SKIP LOCKED"; // 👈 Clave: Salta filas bloqueadas por otros workers

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

        if (empty($ids)) return [];

        // 4. LOCK: Marcar como procesando
        $this->lockItems($ids, $workerId, $nowSql, $table);

        // 5. HYDRATE: Devolver objetos Doctrine
        return $this->hydrateItems($ids);
    }

    /**
     * ⚡ MODO MANUAL / REAL-TIME.
     * Reclama IDs específicos solicitados por el usuario o un evento.
     * * Incluye un WATCHDOG FOCALIZADO crítico: Si intentas reclamar un ID
     * que quedó bloqueado por un error previo (zombie), lo libera primero.
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

        // 2. 🐶 WATCHDOG FOCALIZADO (CRÍTICO)
        // Antes de seleccionar, liberamos forzosamente estos IDs si tienen un lock viejo.
        // Sin esto, 'SKIP LOCKED' ignoraría el registro si quedó trabado en una prueba anterior.
        $expiredSql = $now->modify("-{$ttl} seconds")->format('Y-m-d H:i:s');

        $conn->executeStatement(
            "UPDATE {$table} 
             SET status = 'pending', locked_at = NULL, locked_by = NULL 
             WHERE id IN (:ids) AND locked_at <= :expired",
            ['ids' => $binaryIds, 'expired' => $expiredSql],
            ['ids' => ArrayParameterType::BINARY]
        );

        // 3. SELECCIÓN ATÓMICA
        // Busamos los IDs. Gracias al paso 2, si eran zombies, ahora están libres.
        // Gracias al paso 1 (normalizeToBinary), los bytes coinciden con la DB.
        $sqlCheck = "SELECT id FROM {$table}
                     WHERE id IN (:ids)
                     AND status IN ('pending', 'failed')
                     AND locked_at IS NULL
                     FOR UPDATE SKIP LOCKED";

        $availableBinaryIds = $conn->fetchFirstColumn(
            $sqlCheck,
            ['ids' => $binaryIds],
            ['ids' => ArrayParameterType::BINARY] // 👈 Importante para que PDO no corrompa los bytes
        );

        if (empty($availableBinaryIds)) {
            return [];
        }

        // 4. LOCK & HYDRATE
        $this->lockItems($availableBinaryIds, $workerId, $nowSql, $table);

        return $this->hydrateItems($availableBinaryIds);
    }

    /**
     * Helper privado para aplicar el bloqueo (UPDATE) masivo.
     * Incrementa retry_count y asigna el worker.
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
        // Usamos BIN_TO_UUID(col, true) si tu MySQL es >8.0 para obtener guiones.
        // Si no, BIN_TO_UUID estándar.
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