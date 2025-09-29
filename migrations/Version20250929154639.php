<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250929154639 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add res_unitcaracteristica.restringida_en_resumen; ensure res_estado.habilitar_resumen_publico; drop res_unittipocaracteristica.visible_en_resumen_publico if present.';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform()->getName();
        $isPostgres = str_contains($platform, 'postgres');

        $boolType   = $isPostgres ? 'BOOLEAN'   : 'TINYINT(1)';
        $boolTrue   = $isPostgres ? 'TRUE'      : '1';
        $boolFalse  = $isPostgres ? 'FALSE'     : '0';

        // 1) res_estado.habilitar_resumen_publico (si no existe)
        if ($schema->hasTable('res_estado')) {
            $t = $schema->getTable('res_estado');
            if (!$t->hasColumn('habilitar_resumen_publico')) {
                if ($isPostgres) {
                    $this->addSql("ALTER TABLE res_estado ADD COLUMN habilitar_resumen_publico $boolType NOT NULL DEFAULT $boolFalse");
                } else {
                    $this->addSql("ALTER TABLE res_estado ADD habilitar_resumen_publico $boolType NOT NULL DEFAULT $boolFalse");
                }
            }
        }

        // 2) res_unitcaracteristica.restringida_en_resumen (si no existe)
        if ($schema->hasTable('res_unitcaracteristica')) {
            $t = $schema->getTable('res_unitcaracteristica');
            if (!$t->hasColumn('restringida_en_resumen')) {
                if ($isPostgres) {
                    $this->addSql("ALTER TABLE res_unitcaracteristica ADD COLUMN restringida_en_resumen $boolType NOT NULL DEFAULT $boolFalse");
                } else {
                    $this->addSql("ALTER TABLE res_unitcaracteristica ADD restringida_en_resumen $boolType NOT NULL DEFAULT $boolFalse");
                }
            }
        }

        // 3) Quitar res_unittipocaracteristica.visible_en_resumen_publico si existe
        if ($schema->hasTable('res_unittipocaracteristica')) {
            $t = $schema->getTable('res_unittipocaracteristica');
            if ($t->hasColumn('visible_en_resumen_publico')) {
                if ($isPostgres) {
                    $this->addSql("ALTER TABLE res_unittipocaracteristica DROP COLUMN IF EXISTS visible_en_resumen_publico");
                } else {
                    // MySQL/MariaDB: IF EXISTS funciona en 8.0+; si usas 5.7, quita "IF EXISTS".
                    $this->addSql("ALTER TABLE res_unittipocaracteristica DROP COLUMN IF EXISTS visible_en_resumen_publico");
                }
            }
        }
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform()->getName();
        $isPostgres = str_contains($platform, 'postgres');

        $boolType   = $isPostgres ? 'BOOLEAN'   : 'TINYINT(1)';
        $boolFalse  = $isPostgres ? 'FALSE'     : '0';

        // Reponer visible_en_resumen_publico (por si quieres revertir)
        if ($schema->hasTable('res_unittipocaracteristica')) {
            $t = $schema->getTable('res_unittipocaracteristica');
            if (!$t->hasColumn('visible_en_resumen_publico')) {
                if ($isPostgres) {
                    $this->addSql("ALTER TABLE res_unittipocaracteristica ADD COLUMN visible_en_resumen_publico $boolType NOT NULL DEFAULT $boolFalse");
                } else {
                    $this->addSql("ALTER TABLE res_unittipocaracteristica ADD visible_en_resumen_publico $boolType NOT NULL DEFAULT $boolFalse");
                }
            }
        }

        // Quitar restringida_en_resumen
        if ($schema->hasTable('res_unitcaracteristica')) {
            $t = $schema->getTable('res_unitcaracteristica');
            if ($t->hasColumn('restringida_en_resumen')) {
                if ($isPostgres) {
                    $this->addSql("ALTER TABLE res_unitcaracteristica DROP COLUMN IF EXISTS restringida_en_resumen");
                } else {
                    $this->addSql("ALTER TABLE res_unitcaracteristica DROP COLUMN IF EXISTS restringida_en_resumen");
                }
            }
        }

        // Quitar habilitar_resumen_publico (solo si existe)
        if ($schema->hasTable('res_estado')) {
            $t = $schema->getTable('res_estado');
            if ($t->hasColumn('habilitar_resumen_publico')) {
                if ($isPostgres) {
                    $this->addSql("ALTER TABLE res_estado DROP COLUMN IF EXISTS habilitar_resumen_publico");
                } else {
                    $this->addSql("ALTER TABLE res_estado DROP COLUMN IF EXISTS habilitar_resumen_publico");
                }
            }
        }
    }
}
