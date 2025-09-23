<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250923030548 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MySQL: OneToMany (unit_id/prioridad en res_unitcaracteristica) → ManyToMany con tabla pivote (prioridad por vínculo).';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'mysql',
            'Esta migración está preparada para MySQL.'
        );

        // 1) Crear tabla pivote
        $this->addSql("
            CREATE TABLE IF NOT EXISTS res_unit_caracteristica_link (
                id INT AUTO_INCREMENT NOT NULL,
                unit_id INT NOT NULL,
                unitcaracteristica_id INT NOT NULL,
                prioridad INT DEFAULT NULL,
                creado DATETIME NOT NULL,
                modificado DATETIME NOT NULL,
                INDEX IDX_LINK_UNIT (unit_id),
                INDEX IDX_LINK_CAR (unitcaracteristica_id),
                INDEX IDX_LINK_PRI (prioridad),
                UNIQUE INDEX UNIQ_LINK_UNIT_CAR (unit_id, unitcaracteristica_id),
                PRIMARY KEY(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->addSql("
            ALTER TABLE res_unit_caracteristica_link
                ADD CONSTRAINT FK_LINK_UNIT FOREIGN KEY (unit_id) REFERENCES res_unit (id) ON DELETE CASCADE,
                ADD CONSTRAINT FK_LINK_CAR  FOREIGN KEY (unitcaracteristica_id) REFERENCES res_unitcaracteristica (id) ON DELETE CASCADE
        ");

        // 2) Migrar datos desde el esquema original (unit_id/prioridad)
        $this->addSql("
            INSERT INTO res_unit_caracteristica_link (unit_id, unitcaracteristica_id, prioridad, creado, modificado)
            SELECT unit_id, id AS unitcaracteristica_id, prioridad, NOW(), NOW()
            FROM res_unitcaracteristica
            WHERE unit_id IS NOT NULL
        ");

        // 3) Dropear FK(s) de unit_id (nombre dinámico) e índices si los hubiera
        $fkNames = $this->connection->fetchFirstColumn("
            SELECT CONSTRAINT_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'res_unitcaracteristica'
              AND COLUMN_NAME = 'unit_id'
              AND REFERENCED_TABLE_NAME = 'res_unit'
        ");
        foreach ($fkNames as $fk) {
            $this->addSql(sprintf(
                "ALTER TABLE res_unitcaracteristica DROP FOREIGN KEY `%s`",
                str_replace('`','``',$fk)
            ));
        }

        $idxNames = $this->connection->fetchFirstColumn("
            SELECT INDEX_NAME
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'res_unitcaracteristica'
              AND COLUMN_NAME = 'unit_id'
        ");
        foreach (array_unique($idxNames) as $idx) {
            if ($idx !== 'PRIMARY') {
                $this->addSql(sprintf(
                    "ALTER TABLE res_unitcaracteristica DROP INDEX `%s`",
                    str_replace('`','``',$idx)
                ));
            }
        }

        // 4) Eliminar columnas antiguas (unit_id, prioridad)
        $this->addSql("ALTER TABLE res_unitcaracteristica DROP COLUMN unit_id, DROP COLUMN prioridad");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'mysql',
            'Esta migración está preparada para MySQL.'
        );

        // 1) Re-crear columnas antiguas (sin FK para simplificar el rollback)
        $this->addSql("ALTER TABLE res_unitcaracteristica ADD unit_id INT DEFAULT NULL, ADD prioridad INT DEFAULT NULL");

        // 2) Restaurar un unit_id/prioridad por característica tomando el link con id mínimo
        $this->addSql("
            UPDATE res_unitcaracteristica rc
            JOIN (
                SELECT unitcaracteristica_id, MIN(id) AS link_id
                FROM res_unit_caracteristica_link
                GROUP BY unitcaracteristica_id
            ) t ON t.unitcaracteristica_id = rc.id
            JOIN res_unit_caracteristica_link l ON l.id = t.link_id
            SET rc.unit_id = l.unit_id,
                rc.prioridad = l.prioridad
        ");

        // 3) Borrar tabla pivote
        $this->addSql("DROP TABLE IF EXISTS res_unit_caracteristica_link");
    }
}
