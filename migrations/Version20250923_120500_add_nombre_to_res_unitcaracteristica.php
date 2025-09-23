<?php
// migrations/Version20250923_120500_add_nombre_to_res_unitcaracteristica.php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250923_120500_add_nombre_to_res_unitcaracteristica extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add res_unitcaracteristica.nombre; backfill from res_unittipocaracteristica.nombre; set NOT NULL; create index.';
    }

    public function up(Schema $schema): void
    {
        // 1) Añadir la columna como NULLABLE para poder rellenar
        $this->addSql("ALTER TABLE res_unitcaracteristica ADD nombre VARCHAR(191) DEFAULT NULL");

        // 2) Rellenar desde el tipo (JOIN). Fallback si el tipo no tiene nombre.
        // MySQL/MariaDB style
        $this->addSql("
            UPDATE res_unitcaracteristica uc
            JOIN res_unittipocaracteristica ut
              ON ut.id = uc.unittipocaracteristica_id
            SET uc.nombre = COALESCE(NULLIF(TRIM(ut.nombre), ''), CONCAT('Tipo ', ut.id))
        ");

        // 3) Asegurar que no queden NULLs por alguna razón
        $this->addSql("
            UPDATE res_unitcaracteristica
            SET nombre = CONCAT('Característica ', id)
            WHERE nombre IS NULL OR nombre = ''
        ");

        // 4) Volver la columna NOT NULL
        $this->addSql("ALTER TABLE res_unitcaracteristica MODIFY nombre VARCHAR(191) NOT NULL");

        // 5) Índice
        $this->addSql("CREATE INDEX idx_unitcarac_nombre ON res_unitcaracteristica (nombre)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DROP INDEX idx_unitcarac_nombre ON res_unitcaracteristica");
        $this->addSql("ALTER TABLE res_unitcaracteristica DROP nombre");
    }
}
