<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El nombre operativo del COMPONENTE y del SEGMENTO pasa a vivir en el snapshot.
 *
 * Eran los dos únicos de los cinco niveles que no lo copiaban: servicio y tarifa sí, y éstos se
 * resolvían **en vivo** contra el catálogo desde el navegador. Esa consulta podía volver vacía —y
 * cuando volvía vacía no dejaba un hueco, dejaba otro nombre plausible—. Ahora la ruta es una
 * sola: del maestro al snapshot al añadirlo, y de ahí a La Biblia y a la Orden.
 *
 * ── Qué crea ────────────────────────────────────────────────────────────────
 * - `cotizacion_segmento.nombre_interno_snapshot` — no existía.
 * - `operacion_servicio.nombre_segmento` — el tramo congelado en el cuadro de tráfico, al lado
 *   del `nombre_componente` que ya estaba.
 *
 * ── Qué rellena ─────────────────────────────────────────────────────────────
 * 197 de 199 componentes y 225 de 225 segmentos tienen maestro resoluble; los 2 que faltan son
 * manuales y ya traen el suyo escrito a mano. Nadie se queda sin nombre.
 *
 * ⚠️ Los joins convierten los ids a mano: los `*_maestro_id` son `varchar(36)` **con guiones** y
 * los `id` de `travel_*` son `binary(16)`. Comparados en crudo **no casa ninguno y no da error**:
 * devuelve cero filas. Es la trampa que documenta `App\Api\Filter\UuidRelacionFilter`.
 *
 * ⚠️ El orden importa y no es el que se lee: `addSql()` **encola** para el final de `up()`,
 * mientras que `$this->connection` va inmediato. Por eso las columnas nuevas se crean con
 * `executeStatement()` —ejecutándose YA— antes de rellenarlas. Con `addSql()` arriba, el relleno
 * moriría con `Unknown column`, que es lo que pasó en el despliegue de esta misma tarde.
 */
final class Version20260827220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Nombre operativo de componente y segmento congelado en el snapshot; se acaba la resolución en vivo.';
    }

    public function up(Schema $schema): void
    {
        // Inmediatas, no encoladas: el relleno de abajo las necesita ya existiendo.
        $this->connection->executeStatement(
            'ALTER TABLE cotizacion_segmento ADD nombre_interno_snapshot JSON NOT NULL DEFAULT (JSON_ARRAY())'
        );
        $this->connection->executeStatement(
            'ALTER TABLE operacion_servicio ADD nombre_segmento VARCHAR(255) DEFAULT NULL'
        );

        // 1. El componente: el operativo del maestro, sin pisar lo que el operador escribió.
        $comp = $this->connection->executeStatement(
            'UPDATE cotizacion_cotcomponente k
               JOIN travel_componente m ON HEX(m.id) = UPPER(REPLACE(k.componente_maestro_id, "-", ""))
                SET k.nombre_interno_snapshot = m.nombre_interno
              WHERE (k.nombre_interno_snapshot IS NULL OR k.nombre_interno_snapshot = "")
                AND m.nombre_interno IS NOT NULL AND m.nombre_interno <> ""'
        );

        // 2. El segmento: mismo criterio, pero el campo es i18n de un solo idioma.
        $seg = $this->connection->executeStatement(
            'UPDATE cotizacion_segmento g
               JOIN travel_segmento t ON HEX(t.id) = UPPER(REPLACE(g.segmento_maestro_id, "-", ""))
                SET g.nombre_interno_snapshot = JSON_ARRAY(JSON_OBJECT("language", "es", "content", t.nombre_interno))
              WHERE JSON_LENGTH(g.nombre_interno_snapshot) = 0
                AND t.nombre_interno IS NOT NULL AND t.nombre_interno <> ""'
        );

        // 3. La Biblia: el tramo, desde el segmento del componente que originó la fila.
        $bib = $this->connection->executeStatement(
            'UPDATE operacion_servicio s
               JOIN cotizacion_cotcomponente k ON k.id = s.cotizacion_componente_id
               JOIN cotizacion_segmento g ON g.id = k.cotsegmento_id
                SET s.nombre_segmento = JSON_UNQUOTE(JSON_EXTRACT(g.nombre_interno_snapshot, "$[0].content"))
              WHERE JSON_LENGTH(g.nombre_interno_snapshot) > 0'
        );

        $this->write(sprintf(
            '    <info>componentes: %d · segmentos: %d · filas de La Biblia: %d</info>',
            $comp,
            $seg,
            $bib
        ));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_servicio DROP nombre_segmento');
        $this->addSql('ALTER TABLE cotizacion_segmento DROP nombre_interno_snapshot');
    }
}
