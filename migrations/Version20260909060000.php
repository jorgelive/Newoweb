<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * PMS: `fecha_creacion_beds24` de los cargos, de dígitos UTC a hora del establecimiento.
 *
 * Es el mismo fallo que `Version20260909040000` arregló en las reservas, en un DTO que se quedó
 * fuera de aquella pasada: `Beds24InvoiceItemDto` parseaba `createTime` sin declarar huso, y Beds24
 * lo manda en UTC. **Esta vez la tabla es la del dinero.**
 *
 * ── Cómo se distingue lo que hay que tocar de lo que no ────────────────────
 *
 * En esta columna conviven dos orígenes, y sólo uno está mal:
 *
 * | | `beds24_item_id` | Desfase contra `created_at` | Filas |
 * |---|---|---|---|
 * | Vienen de Beds24 | **no nulo** | −5 h (o carga histórica) | 177 |
 * | Los creamos nosotros | **nulo** | 0 | 34 |
 *
 * Por eso el `WHERE` filtra por `beds24_item_id IS NOT NULL` y no por el desfase: el origen es un
 * hecho, y el desfase sólo es visible en los que entraron por webhook casi al instante. Desplazar
 * los 34 nuestros los rompería, y no habría forma de distinguirlos después.
 *
 * ⚠️ **Se llega al establecimiento por la RESERVA, no por la estancia.** `evento_id` es nulable —un
 * cargo se crea aunque no se resuelva la estancia, y el persister lo declara válido—, así que un
 * `JOIN` por ahí dejaría fuera justo las filas que nadie más va a corregir después: la reimputación
 * posterior asigna el evento pero no vuelve a tocar la fecha. Por `informacion_id → reserva` no hay
 * hueco: comprobado, 177 cargos de Beds24 y **cero** sin reserva, y `establecimiento_id` es NOT NULL.
 *
 * ⚠️ `fecha_factura` NO se toca: es un `date`, un día natural. Restarle horas lo movería al día
 * anterior. Ver `docs/ZonasHorarias.md` §2.
 *
 * ── La ventana del despliegue ──────────────────────────────────────────────
 *
 * Igual que en `Version20260909040000`: entre el `pull` y el `migrate` el código nuevo ya escribe
 * bien, así que se excluye lo tocado en los últimos 15 minutos, con el corte calculado en PHP —
 * MySQL corre en UTC en producción y su reloj daría un corte cinco horas en el futuro.
     *
     * ── ⚠️ El guardia: por qué mira `created_at` y no sólo `updated_at` ────────
     *
     * `TimestampTrait::setTimestampsOnPersist()` **sólo rellena `createdAt`**: una fila recién
     * insertada tiene `updated_at = NULL`. Eso hace que un guardia basado en `updated_at` sea
     * incapaz de distinguir dos casos opuestos, porque los dos son NULL:
     *
     *   · una fila VIEJA que nadie tocó nunca  → hay que desplazarla
     *   · una fila NUEVA que acaba de insertar el código nuevo → NO hay que tocarla
     *
     * Se probaron las dos versiones equivocadas antes de dar con ésta: `updated_at < :corte` dejaba
     * sin convertir 72 cargos y 1 reserva (migración a medias, detectable); añadir
     * `updated_at IS NULL` lo invertía y **corrompía lo que el código nuevo ya había escrito bien**
     * (silencioso, y en la tabla del dinero).
     *
     * `created_at` sí distingue, porque siempre está puesto. Y se añade una segunda red: se salta
     * lo que **ya parece convertido** —una fecha a menos de dos horas de su propio `created_at` es
     * hora de pared; en UTC estaría a unas cinco—, que cubre el caso de ejecutar la migración
     * mucho después del despliegue, cuando el código nuevo lleve horas escribiendo bien.
     *
     * Las dos redes fallan hacia el mismo lado a propósito: **quedarse corto se arregla; pasarse,
     * no.** Una fila sin convertir se ve comparándola con su `created_at`; una desplazada de más es
     * indistinguible de una correcta.
 */
final class Version20260909060000 extends AbstractMigration
{
    private const MARGEN_DESPLIEGUE_MIN = 15;

    public function getDescription(): string
    {
        return 'PMS: fecha_creacion_beds24 de los cargos, de UTC a hora del establecimiento.';
    }

    public function up(Schema $schema): void
    {
        $corte = (new \DateTimeImmutable(sprintf('-%d minutes', self::MARGEN_DESPLIEGUE_MIN)))
            ->format('Y-m-d H:i:s');

        // Lo que el guardia deja fuera, dicho en voz alta.
        $recientes = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM pms_cargo_financiero
              WHERE beds24_item_id IS NOT NULL AND fecha_creacion_beds24 IS NOT NULL
                AND (created_at >= ? OR updated_at >= ?)',
            [$corte, $corte]
        );

        $yaCorrectos = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM pms_cargo_financiero
              WHERE beds24_item_id IS NOT NULL AND fecha_creacion_beds24 IS NOT NULL
                AND TIMESTAMPDIFF(MINUTE, fecha_creacion_beds24, created_at) BETWEEN -120 AND 120',
            []
        );

        $this->write(sprintf(
            '  [i] Cargos sin desplazar: %d por recientes (< %d min) y %d porque su fecha ya está en '
            . 'hora de pared.',
            $recientes,
            self::MARGEN_DESPLIEGUE_MIN,
            $yaCorrectos
        ));

        $this->addSql(<<<'SQL'
            UPDATE pms_cargo_financiero c
            JOIN pms_informacion_financiera i ON i.id = c.informacion_id
            JOIN pms_reserva r                ON r.id = i.reserva_id
            JOIN pms_establecimiento es       ON es.id = r.establecimiento_id
            SET c.fecha_creacion_beds24 = c.fecha_creacion_beds24 - INTERVAL 5 HOUR
            WHERE es.timezone = 'America/Lima'
              AND c.fecha_creacion_beds24 IS NOT NULL
              AND c.beds24_item_id IS NOT NULL
              AND c.created_at < ?
              AND (c.updated_at IS NULL OR c.updated_at < ?)
              AND TIMESTAMPDIFF(MINUTE, c.fecha_creacion_beds24, c.created_at) NOT BETWEEN -120 AND 120
        SQL, [$corte, $corte]);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE pms_cargo_financiero c
            JOIN pms_informacion_financiera i ON i.id = c.informacion_id
            JOIN pms_reserva r                ON r.id = i.reserva_id
            JOIN pms_establecimiento es       ON es.id = r.establecimiento_id
            SET c.fecha_creacion_beds24 = c.fecha_creacion_beds24 + INTERVAL 5 HOUR
            WHERE es.timezone = 'America/Lima'
              AND c.beds24_item_id IS NOT NULL
              AND c.fecha_creacion_beds24 IS NOT NULL
        SQL);
    }
}
