<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * PMS: las fechas de Beds24 pasan de dígitos UTC a hora de pared del establecimiento.
 *
 * ── Qué estaba mal ──────────────────────────────────────────────────────────
 *
 * Beds24 manda `"bookingTime": "2025-07-30T14:30:00"`: **sin `Z`, sin desplazamiento, y en UTC**.
 * `new DateTimeImmutable($s)` etiquetaba esa cadena con el huso de la aplicación, así que en la
 * columna quedaban los dígitos de un sitio con la etiqueta de otro.
 *
 * Medido el 08/09/2026 sobre reservas que entran por webhook casi al instante: `fecha_reserva_canal`
 * iba **exactamente cinco horas por delante de su propio `created_at`**, cuatro de cuatro.
 *
 * No daba error en ninguna parte. `PmsReservaMessageContext` lo compensaba a mano —reinterpretar
 * como UTC y pasar a Lima— y el CRUD de EasyAdmin no, así que el panel enseñaba las reservas
 * creadas cinco horas en el futuro. Compensar en el consumidor funciona mientras haya exactamente
 * uno; en cuanto hay dos, uno de los dos está mal.
 *
 * ── Por qué −5 h y no `CONVERT_TZ` ──────────────────────────────────────────
 *
 * `CONVERT_TZ` necesita las tablas de husos de MySQL cargadas, que no lo están. Se hace con el
 * desplazamiento fijo **acotado a los establecimientos en `America/Lima`**, que es un huso sin
 * cambio de hora —así que el desplazamiento es constante y no hay ambigüedad—. El `WHERE` es la
 * salvaguarda: si algún día hay un establecimiento en otro huso, esas filas **no se tocan** en vez
 * de corromperse en silencio, y hará falta una migración pensada para él.
 *
 * `primera_fecha_reserva_canal` y `ultima_fecha_modificacion_canal` son agregados de las columnas de
 * arriba (`PmsReservaRecalculoService` las calcula como `MIN`/`MAX`), así que se desplazan igual.
 *
 * ⚠️ **La segunda casi se queda fuera.** Se escribió «el agregado» en singular y hay dos: los
 * eventos habrían quedado corregidos y la copia de la reserva 5 h por delante, en 351 filas. Las
 * reservas ya canceladas no vuelven a pasar por el pull, así que se habrían quedado así para
 * siempre — el panel diciendo una hora y el evento otra. Lo cazó una segunda revisión, no la
 * primera.
 *
 * ── ⚠️ La ventana del despliegue ────────────────────────────────────────────
 *
 * El despliegue es `pull → build → migrate`. Entre el `pull` (que ya deja vivo el código nuevo) y
 * el `migrate` pasan los minutos del build, y en ese hueco el cron y los webhooks escriben filas
 * **ya en hora de pared**. Restarles 5 h las rompería, y este `UPDATE` no es idempotente.
 *
 * Por eso el `WHERE` excluye lo tocado en los últimos 15 minutos: son las filas que sólo pudo
 * escribir el código nuevo. El corte se calcula en **PHP**, no con `NOW()`, porque en producción
 * MySQL corre en UTC y PHP en `America/Lima` — `NOW()` daría un corte cinco horas en el futuro y no
 * excluiría nada.
 *
 * El residuo: una fila que el código VIEJO escribió en esos mismos 15 minutos se queda sin
 * corregir. Es un puñado como mucho, y quedarse corto se arregla a mano; pasarse deja una fecha mal
 * sin forma de saber cuál. La migración dice cuántas saltó.
 */
final class Version20260909040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'PMS: fechas de Beds24 de UTC a hora del establecimiento (−5 h en America/Lima).';
    }

    /** Minutos de gracia para no pisar lo que ya escribió el código nuevo durante el despliegue. */
    private const MARGEN_DESPLIEGUE_MIN = 15;

    public function up(Schema $schema): void
    {
        // El corte, en hora de pared: es la convención de las columnas y del reloj de PHP.
        $corte = (new \DateTimeImmutable(sprintf('-%d minutes', self::MARGEN_DESPLIEGUE_MIN)))
            ->format('Y-m-d H:i:s');

        // Lo que el guardia deja fuera, dicho en voz alta: quedarse corto es recuperable, pero
        // sólo si alguien sabe que pasó.
        $recientes = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM pms_evento_calendario
              WHERE fecha_reserva_canal IS NOT NULL
                AND (created_at >= ? OR updated_at >= ?)',
            [$corte, $corte]
        );

        $yaCorrectos = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM pms_evento_calendario
              WHERE fecha_reserva_canal IS NOT NULL
                AND TIMESTAMPDIFF(MINUTE, fecha_reserva_canal, created_at) BETWEEN -120 AND 120',
            []
        );

        $this->write(sprintf(
            '  [i] Eventos sin desplazar: %d por recientes (< %d min) y %d porque su fecha ya está '
            . 'a menos de 2 h de su created_at, o sea ya en hora de pared.',
            $recientes,
            self::MARGEN_DESPLIEGUE_MIN,
            $yaCorrectos
        ));

        $this->addSql(<<<'SQL'
            UPDATE pms_evento_calendario e
            JOIN pms_unidad u ON u.id = e.pms_unidad_id
            JOIN pms_establecimiento es ON es.id = u.establecimiento_id
            SET e.fecha_reserva_canal = e.fecha_reserva_canal - INTERVAL 5 HOUR
            WHERE es.timezone = 'America/Lima'
              AND e.fecha_reserva_canal IS NOT NULL
              AND e.created_at < ?
              AND (e.updated_at IS NULL OR e.updated_at < ?)
              AND TIMESTAMPDIFF(MINUTE, e.fecha_reserva_canal, e.created_at) NOT BETWEEN -120 AND 120
        SQL, [$corte, $corte]);

        $this->addSql(<<<'SQL'
            UPDATE pms_evento_calendario e
            JOIN pms_unidad u ON u.id = e.pms_unidad_id
            JOIN pms_establecimiento es ON es.id = u.establecimiento_id
            SET e.fecha_modificacion_canal = e.fecha_modificacion_canal - INTERVAL 5 HOUR
            WHERE es.timezone = 'America/Lima'
              AND e.fecha_modificacion_canal IS NOT NULL
              AND e.created_at < ?
              AND (e.updated_at IS NULL OR e.updated_at < ?)
              AND TIMESTAMPDIFF(MINUTE, e.fecha_modificacion_canal, e.created_at) NOT BETWEEN -120 AND 120
        SQL, [$corte, $corte]);

        // Los dos agregados de la reserva. `ultima_fecha_modificacion_canal` es el gemelo que casi
        // se queda fuera: `PmsReservaRecalculoService` la calcula como MAX() de la columna de
        // arriba, igual que `primera_fecha_reserva_canal` es su MIN().
        $this->addSql(<<<'SQL'
            UPDATE pms_reserva r
            JOIN pms_establecimiento es ON es.id = r.establecimiento_id
            SET r.primera_fecha_reserva_canal = r.primera_fecha_reserva_canal - INTERVAL 5 HOUR
            WHERE es.timezone = 'America/Lima'
              AND r.primera_fecha_reserva_canal IS NOT NULL
              AND r.created_at < ?
              AND (r.updated_at IS NULL OR r.updated_at < ?)
              AND TIMESTAMPDIFF(MINUTE, r.primera_fecha_reserva_canal, r.created_at) NOT BETWEEN -120 AND 120
        SQL, [$corte, $corte]);

        $this->addSql(<<<'SQL'
            UPDATE pms_reserva r
            JOIN pms_establecimiento es ON es.id = r.establecimiento_id
            SET r.ultima_fecha_modificacion_canal = r.ultima_fecha_modificacion_canal - INTERVAL 5 HOUR
            WHERE es.timezone = 'America/Lima'
              AND r.ultima_fecha_modificacion_canal IS NOT NULL
              AND r.created_at < ?
              AND (r.updated_at IS NULL OR r.updated_at < ?)
              AND TIMESTAMPDIFF(MINUTE, r.ultima_fecha_modificacion_canal, r.created_at) NOT BETWEEN -120 AND 120
        SQL, [$corte, $corte]);
    }

    public function down(Schema $schema): void
    {
        // Sin el filtro de tiempo: al revertir se devuelve TODO lo que la ida pudo tocar, porque
        // no hay forma de saber cuáles fueron. Es lo correcto para un `down` de un cambio de
        // convención — y por eso este `down` no debería usarse con tráfico entrando.
        $this->addSql("UPDATE pms_evento_calendario e JOIN pms_unidad u ON u.id = e.pms_unidad_id JOIN pms_establecimiento es ON es.id = u.establecimiento_id SET e.fecha_reserva_canal = e.fecha_reserva_canal + INTERVAL 5 HOUR WHERE es.timezone = 'America/Lima' AND e.fecha_reserva_canal IS NOT NULL");
        $this->addSql("UPDATE pms_evento_calendario e JOIN pms_unidad u ON u.id = e.pms_unidad_id JOIN pms_establecimiento es ON es.id = u.establecimiento_id SET e.fecha_modificacion_canal = e.fecha_modificacion_canal + INTERVAL 5 HOUR WHERE es.timezone = 'America/Lima' AND e.fecha_modificacion_canal IS NOT NULL");
        $this->addSql("UPDATE pms_reserva r JOIN pms_establecimiento es ON es.id = r.establecimiento_id SET r.primera_fecha_reserva_canal = r.primera_fecha_reserva_canal + INTERVAL 5 HOUR WHERE es.timezone = 'America/Lima' AND r.primera_fecha_reserva_canal IS NOT NULL");
        $this->addSql("UPDATE pms_reserva r JOIN pms_establecimiento es ON es.id = r.establecimiento_id SET r.ultima_fecha_modificacion_canal = r.ultima_fecha_modificacion_canal + INTERVAL 5 HOUR WHERE es.timezone = 'America/Lima' AND r.ultima_fecha_modificacion_canal IS NOT NULL");
    }
}
