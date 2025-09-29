<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250929_154639 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MySQL/MariaDB: Add res_unitcaracteristica.restringida_en_resumen; ensure res_estado.habilitar_resumen_publico; drop res_unittipocaracteristica.visible_en_resumen_publico if present.';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform()->getName();
        // MySQL y MariaDB
        $this->abortIf($platform !== 'mysql' && $platform !== 'mariadb', sprintf('Migration can only be executed safely on MySQL/MariaDB. Detected: %s', $platform));

        // 1) res_estado.habilitar_resumen_publico (add si no existe)
        if ($schema->hasTable('res_estado')) {
            $t = $schema->getTable('res_estado');
            if (!$t->hasColumn('habilitar_resumen_publico')) {
                $this->addSql("ALTER TABLE res_estado ADD habilitar_resumen_publico TINYINT(1) NOT NULL DEFAULT 0");
            }
        }

        // 2) res_unitcaracteristica.restringida_en_resumen (add si no existe)
        if ($schema->hasTable('res_unitcaracteristica')) {
            $t = $schema->getTable('res_unitcaracteristica');
            if (!$t->hasColumn('restringida_en_resumen')) {
                $this->addSql("ALTER TABLE res_unitcaracteristica ADD restringida_en_resumen TINYINT(1) NOT NULL DEFAULT 0");
            }
        }

        // 3) Quitar res_unittipocaracteristica.visible_en_resumen_publico si existe
        if ($schema->hasTable('res_unittipocaracteristica')) {
            $t = $schema->getTable('res_unittipocaracteristica');
            if ($t->hasColumn('visible_en_resumen_publico')) {
                // MySQL/MariaDB: sin "IF EXISTS"
                $this->addSql("ALTER TABLE res_unittipocaracteristica DROP COLUMN visible_en_resumen_publico");
            }
        }
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform()->getName();
        $this->abortIf($platform !== 'mysql' && $platform !== 'mariadb', sprintf('Migration can only be executed safely on MySQL/MariaDB. Detected: %s', $platform));

        // Reponer visible_en_resumen_publico si no existe
        if ($schema->hasTable('res_unittipocaracteristica')) {
            $t = $schema->getTable('res_unittipocaracteristica');
            if (!$t->hasColumn('visible_en_resumen_publico')) {
                $this->addSql("ALTER TABLE res_unittipocaracteristica ADD visible_en_resumen_publico TINYINT(1) NOT NULL DEFAULT 0");
            }
        }

        // Quitar restringida_en_resumen (solo si existe)
        if ($schema->hasTable('res_unitcaracteristica')) {
            $t = $schema->getTable('res_unitcaracteristica');
            if ($t->hasColumn('restringida_en_resumen')) {
                $this->addSql("ALTER TABLE res_unitcaracteristica DROP COLUMN restringida_en_resumen");
            }
        }

        // Quitar habilitar_resumen_publico (solo si existe)
        if ($schema->hasTable('res_estado')) {
            $t = $schema->getTable('res_estado');
            if ($t->hasColumn('habilitar_resumen_publico')) {
                $this->addSql("ALTER TABLE res_estado DROP COLUMN habilitar_resumen_publico");
            }
        }
    }
}
