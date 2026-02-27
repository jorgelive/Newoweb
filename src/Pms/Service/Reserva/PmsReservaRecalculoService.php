<?php
declare(strict_types=1);

namespace App\Pms\Service\Reserva;

use App\Pms\Entity\PmsReserva;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class PmsReservaRecalculoService
{
    /**
     * Recalcula datos de la reserva a partir de TODOS sus eventos persistidos.
     *
     * Estrategia "blindada":
     * - Convertimos UUIDs a BINARY(16)
     * - Ejecutamos un UPDATE masivo con LEFT JOIN a una subquery agregada.
     * - Usamos GROUP_CONCAT para agrupar textos únicos (canales, referencias).
     * - Calcula dinámicamente el canal principal (Si hay OTA, manda OTA. Si no, Directo).
     * - Refrescamos (refresh) el UnitOfWork para evitar objetos cacheados obsoletos.
     *
     * @param string[] $reservaIds UUIDs RFC4122 (string)
     */
    public function recalcularDesdeEventos(array $reservaIds, EntityManagerInterface $em): void
    {
        // Limpieza y validación de IDs
        $reservaIds = array_values(array_unique(array_filter($reservaIds, static fn ($v) => is_string($v) && $v !== '')));
        if ($reservaIds === []) {
            return;
        }

        $conn = $em->getConnection();

        // Loteo por seguridad (evita queries gigantes y límites de placeholders)
        foreach (array_chunk($reservaIds, 400) as $chunk) {
            $binaryIds = [];
            foreach ($chunk as $idStr) {
                // Si viene un UUID inválido, mejor explotar aquí que dejar data corrupta
                $binaryIds[] = Uuid::fromString($idStr)->toBinary();
            }

            // Placeholders para IN (...)
            $in = implode(',', array_fill(0, count($binaryIds), '?'));

            $sql = <<<SQL
UPDATE pms_reserva r
LEFT JOIN (
    SELECT
        e.reserva_id AS reserva_id,
        MIN(DATE(e.inicio)) AS fechaMin,
        MAX(DATE(e.fin))    AS fechaMax,
        COALESCE(SUM(COALESCE(e.monto, 0)), 0)            AS totalMonto,
        COALESCE(SUM(COALESCE(e.comision, 0)), 0)         AS totalComision,
        COALESCE(SUM(COALESCE(e.cantidad_adultos, 0)), 0) AS totalAdultos,
        COALESCE(SUM(COALESCE(e.cantidad_ninos, 0)), 0)   AS totalNinos,
        
        -- Agregaciones de Texto (Eliminando nulos y vacíos, separados por | )
        GROUP_CONCAT(DISTINCT NULLIF(TRIM(e.channel_id), '') SEPARATOR ' | ')         AS canalesAgregados,
        GROUP_CONCAT(DISTINCT NULLIF(TRIM(e.referencia_canal), '') SEPARATOR ' | ')   AS refAgregadas,
        GROUP_CONCAT(DISTINCT NULLIF(TRIM(e.hora_llegada_canal), '') SEPARATOR ' | ') AS horasAgregadas,
        
        -- Fechas extremas de creación/modificación en el canal
        MIN(e.fecha_reserva_canal)      AS minFechaReserva,
        MAX(e.fecha_modificacion_canal) AS maxFechaModif,
        
        -- ✅ LÓGICA DE CANAL PRINCIPAL
        -- Si existe al menos un canal que NO sea 'directo', toma ese.
        -- Si todos son 'directo' (o nulos), el resultado será NULL en el MAX.
        MAX(CASE WHEN e.channel_id != 'directo' THEN e.channel_id END) AS canalDominante
        
    FROM pms_evento_calendario e
    WHERE e.reserva_id IN ($in)
    GROUP BY e.reserva_id
) s ON s.reserva_id = r.id
SET
    r.fecha_llegada                   = s.fechaMin,
    r.fecha_salida                    = s.fechaMax,
    r.monto_total                     = COALESCE(s.totalMonto, 0),
    r.comision_total                  = COALESCE(s.totalComision, 0),
    r.cantidad_adultos                = COALESCE(s.totalAdultos, 0),
    r.cantidad_ninos                  = COALESCE(s.totalNinos, 0),
    
    r.canales_aggregate               = s.canalesAgregados,
    r.referencia_canal_aggregate      = s.refAgregadas,
    r.hora_llegada_canal_aggregate    = s.horasAgregadas,
    r.primera_fecha_reserva_canal     = s.minFechaReserva,
    r.ultima_fecha_modificacion_canal = s.maxFechaModif,
    
    -- ✅ ASIGNACIÓN DEL CANAL (Si el subquery devuelve NULL, usamos 'directo')
    r.channel_id                      = COALESCE(s.canalDominante, 'directo')
    
WHERE r.id IN ($in)
SQL;

            // Pasamos los IDs dos veces (subquery IN + WHERE IN)
            $params = array_merge($binaryIds, $binaryIds);

            // Tipamos como BINARY para que MySQL compare 16 bytes reales
            $types = array_fill(0, count($params), ParameterType::BINARY);

            // 1. Ejecutamos la consulta pura en MySQL
            $conn->executeStatement($sql, $params, $types);

            // 2. Refrescamos las entidades en el UnitOfWork de Doctrine
            foreach ($chunk as $idStr) {
                // find() busca primero en memoria, lo cual es gratis si ya está cacheado
                $reserva = $em->find(PmsReserva::class, $idStr);

                if ($reserva) {
                    // refresh() obliga a Doctrine a lanzar un SELECT a la base de datos
                    // sobrescribiendo el objeto actual con los montos, textos y el nuevo CANAL
                    $em->refresh($reserva);
                }
            }
        }
    }
}