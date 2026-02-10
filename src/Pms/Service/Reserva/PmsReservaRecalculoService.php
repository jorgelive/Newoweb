<?php
declare(strict_types=1);

namespace App\Pms\Service\Reserva;

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
     * - Ejecutamos un UPDATE masivo con LEFT JOIN a una subquery agregada
     * - Sin N updates, sin IDENTITY(), sin hydration raro
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

            /**
             * AJUSTA ESTOS NOMBRES SI DIFIEREN EN TU DB:
             * - pms_reserva (tabla reservas)
             * - pms_evento_calendario (tabla eventos)
             * - e.reserva_id (FK binaria a reserva)
             * - columnas: inicio, fin, monto, comision, cantidad_adultos, cantidad_ninos
             * - columnas en reserva: fecha_llegada, fecha_salida, monto_total, comision_total, cantidad_adultos, cantidad_ninos
             */
            $sql = <<<SQL
UPDATE pms_reserva r
LEFT JOIN (
    SELECT
        e.reserva_id AS reserva_id,
        MIN(DATE(e.inicio)) AS fechaMin,
        MAX(DATE(e.fin))    AS fechaMax,
        COALESCE(SUM(COALESCE(e.monto, 0)), 0)           AS totalMonto,
        COALESCE(SUM(COALESCE(e.comision, 0)), 0)        AS totalComision,
        COALESCE(SUM(COALESCE(e.cantidad_adultos, 0)), 0) AS totalAdultos,
        COALESCE(SUM(COALESCE(e.cantidad_ninos, 0)), 0)   AS totalNinos
    FROM pms_evento_calendario e
    WHERE e.reserva_id IN ($in)
    GROUP BY e.reserva_id
) s ON s.reserva_id = r.id
SET
    r.fecha_llegada    = s.fechaMin,
    r.fecha_salida     = s.fechaMax,
    r.monto_total      = COALESCE(s.totalMonto, 0),
    r.comision_total   = COALESCE(s.totalComision, 0),
    r.cantidad_adultos = COALESCE(s.totalAdultos, 0),
    r.cantidad_ninos   = COALESCE(s.totalNinos, 0)
WHERE r.id IN ($in)
SQL;

            // Pasamos los IDs dos veces (subquery IN + WHERE IN)
            $params = array_merge($binaryIds, $binaryIds);

            // Tipamos todo como BINARY para que MySQL compare 16 bytes reales
            $types = array_fill(0, count($params), ParameterType::BINARY);

            $conn->executeStatement($sql, $params, $types);
        }
    }
}