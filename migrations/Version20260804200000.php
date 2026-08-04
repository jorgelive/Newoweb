<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El catálogo público pasa a ser una entidad propia, con su propia estructura.
 *
 * ANTES: el escaparate reutilizaba el árbol de secciones de la GUÍA y solo
 * dejaba pasar los ítems marcados `publico`. Heredaba por tanto la arquitectura
 * de la información de un manual operativo —la guía agrupa por *cuándo lo
 * necesitas*— y al visitante le salía un encabezado «Entrada a la casa ·
 * Llegada, llaves y acceso» en mitad de una página comercial.
 *
 * AHORA: `pms_catalogo` (1:1 con la unidad, espejo de `pms_guia`) y
 * `pms_catalogo_has_item`, un join con atributos —bloque + orden— que coloca
 * cada `PmsGuiaItem` en el escaparate. El contenido se REUTILIZA, no se
 * duplica: la descripción de la casita se escribe una vez.
 *
 * BACKFILL. Por cada unidad con ítems públicos se crea su catálogo, y cada ítem
 * `publico` se convierte en una fila del join. El bloque se deduce del ICONO,
 * que es el mismo criterio con el que Version20260804150000 pobló el catálogo
 * en su día — y la última vez que hace falta ese heurístico: a partir de aquí el
 * bloque es un campo explícito que el editor elige.
 *
 *   fa-images        → fotos
 *   fa-location-dot  → ubicacion
 *   fa-circle-info   → descripcion
 *   (cualquier otro) → descripcion
 *
 * El `orden` se hereda del que ya tenían dentro de su sección, para que la
 * secuencia relativa no cambie de golpe.
 *
 * NO retira `PmsGuiaVisibilidad::Publico`: eso va en la migración siguiente,
 * para que este paso sea reversible por sí solo.
 */
final class Version20260804200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea pms_catalogo y pms_catalogo_has_item, y siembra el escaparate desde los items publicos.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE pms_catalogo (
                id BINARY(16) NOT NULL,
                unidad_id BINARY(16) NOT NULL,
                activo TINYINT(1) DEFAULT 1 NOT NULL,
                titulo JSON NOT NULL,
                subtitulo JSON DEFAULT NULL,
                auto_translate TINYINT(1) DEFAULT 1 NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME DEFAULT NULL,
                UNIQUE INDEX uniq_catalogo_unidad (unidad_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE pms_catalogo_has_item (
                id BINARY(16) NOT NULL,
                catalogo_id BINARY(16) NOT NULL,
                item_id BINARY(16) NOT NULL,
                bloque VARCHAR(20) NOT NULL,
                orden INT DEFAULT 0 NOT NULL,
                activo TINYINT(1) DEFAULT 1 NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME DEFAULT NULL,
                INDEX idx_catalogo (catalogo_id),
                INDEX idx_item (item_id),
                UNIQUE INDEX uniq_catalogo_item (catalogo_id, item_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql('ALTER TABLE pms_catalogo ADD CONSTRAINT fk_catalogo_unidad FOREIGN KEY (unidad_id) REFERENCES pms_unidad (id)');
        $this->addSql('ALTER TABLE pms_catalogo_has_item ADD CONSTRAINT fk_chi_catalogo FOREIGN KEY (catalogo_id) REFERENCES pms_catalogo (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE pms_catalogo_has_item ADD CONSTRAINT fk_chi_item FOREIGN KEY (item_id) REFERENCES pms_guia_item (id) ON DELETE CASCADE');

        // ── BACKFILL ────────────────────────────────────────────────────────
        // 1. Un catálogo por unidad que tenga al menos un ítem público. El
        //    título arranca copiado del de la guía: es un punto de partida
        //    razonable y el editor lo cambia cuando quiera.
        $this->addSql(<<<'SQL'
            INSERT INTO pms_catalogo (id, unidad_id, activo, titulo, subtitulo, auto_translate, created_at, updated_at)
            SELECT
                UNHEX(REPLACE(UUID(), '-', '')),
                g.unidad_id,
                1,
                g.titulo,
                NULL,
                1,
                NOW(),
                NOW()
            FROM pms_guia g
            WHERE EXISTS (
                SELECT 1
                FROM pms_guia_has_seccion ghs
                INNER JOIN pms_guia_seccion_has_item gshi ON gshi.seccion_id = ghs.seccion_id
                INNER JOIN pms_guia_item i                ON i.id = gshi.item_id
                WHERE ghs.guia_id = g.id
                  AND i.visibilidad = 'publico'
            )
            SQL);

        // 2. Cada ítem público se coloca en su bloque. DISTINCT porque un ítem
        //    puede estar en varias secciones de la misma guía y aquí solo debe
        //    aparecer una vez (lo impide además el índice único).
        $this->addSql(<<<'SQL'
            INSERT INTO pms_catalogo_has_item (id, catalogo_id, item_id, bloque, orden, activo, created_at, updated_at)
            SELECT
                UNHEX(REPLACE(UUID(), '-', '')),
                c.id,
                t.item_id,
                t.bloque,
                t.orden,
                1,
                NOW(),
                NOW()
            FROM (
                SELECT DISTINCT
                    g.unidad_id AS unidad_id,
                    i.id        AS item_id,
                    CASE i.icono
                        WHEN 'fa-images'       THEN 'fotos'
                        WHEN 'fa-location-dot' THEN 'ubicacion'
                        ELSE 'descripcion'
                    END         AS bloque,
                    MIN(gshi.orden) AS orden
                FROM pms_guia g
                INNER JOIN pms_guia_has_seccion ghs        ON ghs.guia_id = g.id
                INNER JOIN pms_guia_seccion_has_item gshi  ON gshi.seccion_id = ghs.seccion_id
                INNER JOIN pms_guia_item i                 ON i.id = gshi.item_id
                WHERE i.visibilidad = 'publico'
                GROUP BY g.unidad_id, i.id, i.icono
            ) t
            INNER JOIN pms_catalogo c ON c.unidad_id = t.unidad_id
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_catalogo_has_item DROP FOREIGN KEY fk_chi_item');
        $this->addSql('ALTER TABLE pms_catalogo_has_item DROP FOREIGN KEY fk_chi_catalogo');
        $this->addSql('ALTER TABLE pms_catalogo DROP FOREIGN KEY fk_catalogo_unidad');
        $this->addSql('DROP TABLE pms_catalogo_has_item');
        $this->addSql('DROP TABLE pms_catalogo');
    }
}
