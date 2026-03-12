<?php
declare(strict_types=1);

namespace App\Pms\Service\Reserva;

use App\Message\Factory\MessageConversationFactory;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsReserva;
use App\Pms\Service\Message\PmsReservaMessageContext;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class PmsReservaRecalculoService
{
    // 🔥 1. INYECTAMOS EL FACTORY DEL CHAT
    public function __construct(
        private readonly MessageConversationFactory $messageFactory
    ) {}

    public function recalcularDesdeEventos(array $reservaIds, EntityManagerInterface $entityManager, $flush): void
    {
        $reservaIds = array_values(array_unique(array_filter($reservaIds, static fn ($v) => is_string($v) && $v !== '')));
        if ($reservaIds === []) {
            return;
        }

        $conn = $entityManager->getConnection();
        $estadoCancelada = PmsEventoEstado::CODIGO_CANCELADA;

        foreach (array_chunk($reservaIds, 400) as $chunk) {
            $binaryIds = [];
            foreach ($chunk as $idStr) {
                $binaryIds[] = Uuid::fromString($idStr)->toBinary();
            }

            $in = implode(',', array_fill(0, count($binaryIds), '?'));

            $sql = <<<SQL
UPDATE pms_reserva r
LEFT JOIN (
    SELECT
        e.reserva_id AS reserva_id,
        
        COALESCE(MIN(CASE WHEN e.estado_id != '$estadoCancelada' THEN DATE(e.inicio) END), MIN(DATE(e.inicio))) AS fechaMin,
        COALESCE(MAX(CASE WHEN e.estado_id != '$estadoCancelada' THEN DATE(e.fin) END), MAX(DATE(e.fin))) AS fechaMax,
        
        COALESCE(SUM(CASE WHEN e.estado_id != '$estadoCancelada' THEN COALESCE(e.monto, 0) ELSE 0 END), 0) AS totalMonto,
        COALESCE(SUM(CASE WHEN e.estado_id != '$estadoCancelada' THEN COALESCE(e.comision, 0) ELSE 0 END), 0) AS totalComision,
        COALESCE(SUM(CASE WHEN e.estado_id != '$estadoCancelada' THEN COALESCE(e.cantidad_adultos, 0) ELSE 0 END), 0) AS totalAdultos,
        COALESCE(SUM(CASE WHEN e.estado_id != '$estadoCancelada' THEN COALESCE(e.cantidad_ninos, 0) ELSE 0 END), 0) AS totalNinos,
        
        GROUP_CONCAT(DISTINCT NULLIF(TRIM(e.channel_id), '') SEPARATOR ' | ') AS canalesAgregados,
        GROUP_CONCAT(DISTINCT NULLIF(TRIM(e.referencia_canal), '') SEPARATOR ' | ') AS refAgregadas,
        GROUP_CONCAT(DISTINCT NULLIF(TRIM(e.hora_llegada_canal), '') SEPARATOR ' | ') AS horasAgregadas,
        
        -- 🔥 AGRUPACIÓN DE NOMBRES DE UNIDADES (Solo vivos)
        GROUP_CONCAT(DISTINCT CASE WHEN e.estado_id != '$estadoCancelada' THEN NULLIF(TRIM(u.nombre), '') END SEPARATOR ', ') AS unidadesAgregadas,
        
        MIN(e.fecha_reserva_canal) AS minFechaReserva,
        MAX(e.fecha_modificacion_canal) AS maxFechaModif,
        
        MAX(CASE WHEN e.estado_id != '$estadoCancelada' AND e.channel_id != 'directo' THEN e.channel_id END) AS canalDominante
        
    FROM pms_evento_calendario e
    LEFT JOIN pms_unidad u ON e.pms_unidad_id = u.id 
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
    r.unidades_aggregate              = s.unidadesAgregadas,
    
    r.primera_fecha_reserva_canal     = s.minFechaReserva,
    r.ultima_fecha_modificacion_canal = s.maxFechaModif,
    
    r.channel_id                      = COALESCE(s.canalDominante, 'directo')
    
WHERE r.id IN ($in)
SQL;

            $params = array_merge($binaryIds, $binaryIds);
            $types = array_fill(0, count($params), ParameterType::BINARY);

            $conn->executeStatement($sql, $params, $types);

            // 🔥 2. ACTUALIZAMOS EL CHAT CON LOS DATOS FRESCOS
            foreach ($chunk as $idStr) {
                $reserva = $entityManager->find(PmsReserva::class, $idStr);
                if ($reserva) {
                    // Refrescamos la reserva con los datos recién calculados por el SQL
                    $entityManager->refresh($reserva);

                    // Envolvemos la reserva en su adaptador
                    $context = new PmsReservaMessageContext($reserva);

                    // Actualizamos el chat (con true para que haga flush de la conversación)
                    $this->messageFactory->upsertFromContext($context, $flush);
                }
            }
        }
    }
}