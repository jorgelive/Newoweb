<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Añade `slug` a establecimientos y unidades para la URL pública del catálogo
 * (`/casita/casita-1`). Ver docs/PmsGuiaHuesped.md §5.
 *
 * El orden importa: primero se crea la columna SIN restricción, luego se
 * rellena, y solo al final se añade el índice único. Crear la columna ya
 * UNIQUE sobre una tabla con filas la dejaría con todos los valores a NULL
 * —válido en MySQL, porque NULL no colisiona consigo mismo— pero la primera
 * unidad que se guardara sin slug rompería, y el backfill posterior tendría
 * que pelearse con la restricción mientras escribe.
 *
 * El backfill se hace en SQL y no con el listener PHP porque una migración no
 * debe depender del contenedor de servicios: si mañana cambia el listener, la
 * migración tiene que seguir produciendo el mismo resultado.
 */
final class Version20260803230500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade slug único a pms_establecimiento y pms_unidad, con backfill derivado del nombre.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_establecimiento ADD slug VARCHAR(180) DEFAULT NULL');
        $this->addSql('ALTER TABLE pms_unidad ADD slug VARCHAR(150) DEFAULT NULL');

        // ── Establecimientos ────────────────────────────────────────────────
        // Sluggado en SQL: minúsculas, los separadores comunes a guion y fuera
        // todo lo que no sea [a-z0-9-]. No translitera acentos (MySQL no trae
        // una función para eso): 'Casita Ñusta' queda como 'casita-usta'. Es
        // aceptable para el arranque —son pocas filas y el slug se puede
        // corregir a mano en el panel— y las altas nuevas sí pasan por
        // AsciiSlugger en PmsSlugListener, que sí translitera.
        $this->addSql(<<<'SQL'
            UPDATE pms_establecimiento
            SET slug = TRIM(BOTH '-' FROM
                REGEXP_REPLACE(
                    REGEXP_REPLACE(LOWER(COALESCE(nombre_comercial, 'alojamiento')), '[^a-z0-9]+', '-'),
                    '-{2,}', '-'
                )
            )
            WHERE slug IS NULL
            SQL);

        // ── Unidades ────────────────────────────────────────────────────────
        // Se prefija con el slug del establecimiento, igual que hace el
        // listener, para que dos "Habitación 1" de hoteles distintos no
        // colisionen en un espacio de nombres que es global.
        $this->addSql(<<<'SQL'
            UPDATE pms_unidad u
            INNER JOIN pms_establecimiento e ON e.id = u.establecimiento_id
            SET u.slug = TRIM(BOTH '-' FROM
                REGEXP_REPLACE(
                    REGEXP_REPLACE(
                        LOWER(CONCAT(COALESCE(e.slug, ''), '-', COALESCE(u.nombre, 'unidad'))),
                        '[^a-z0-9]+', '-'
                    ),
                    '-{2,}', '-'
                )
            )
            WHERE u.slug IS NULL
            SQL);

        // ── Desempate ───────────────────────────────────────────────────────
        // Si tras el backfill quedan duplicados (dos unidades con el mismo
        // nombre en el mismo establecimiento), se numeran por orden de creación
        // dejando intacta la primera: -2, -3… Igual que el listener.
        $this->addSql(<<<'SQL'
            UPDATE pms_unidad u
            INNER JOIN (
                SELECT id, slug,
                       ROW_NUMBER() OVER (PARTITION BY slug ORDER BY created_at, id) AS fila
                FROM pms_unidad
            ) d ON d.id = u.id
            SET u.slug = CONCAT(d.slug, '-', d.fila)
            WHERE d.fila > 1
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE pms_establecimiento e
            INNER JOIN (
                SELECT id, slug,
                       ROW_NUMBER() OVER (PARTITION BY slug ORDER BY created_at, id) AS fila
                FROM pms_establecimiento
            ) d ON d.id = e.id
            SET e.slug = CONCAT(d.slug, '-', d.fila)
            WHERE d.fila > 1
            SQL);

        $this->addSql('CREATE UNIQUE INDEX uniq_pms_establecimiento_slug ON pms_establecimiento (slug)');
        $this->addSql('CREATE UNIQUE INDEX uniq_pms_unidad_slug ON pms_unidad (slug)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_pms_establecimiento_slug ON pms_establecimiento');
        $this->addSql('DROP INDEX uniq_pms_unidad_slug ON pms_unidad');
        $this->addSql('ALTER TABLE pms_establecimiento DROP slug');
        $this->addSql('ALTER TABLE pms_unidad DROP slug');
    }
}
