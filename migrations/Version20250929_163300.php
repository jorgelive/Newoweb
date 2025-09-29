<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250929_163300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MySQL: add res_unittipocaracteristica.restringido_en_resumen and res_estado.habilitar_resumen_publico (idempotente).';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform()->getName();
        $this->abortIf($platform !== 'mysql' && $platform !== 'mariadb', sprintf('This migration is for MySQL/MariaDB only. Detected: %s', $platform));

        // 1) res_estado.habilitar_resumen_publico
        if ($schema->hasTable('res_estado')) {
            $t = $schema->getTable('res_estado');
            if (!$t->hasColumn('habilitar_resumen_publico')) {
                $this->addSql("ALTER TABLE res_estado ADD habilitar_resumen_publico TINYINT(1) NOT NULL DEFAULT 0");
            }
        }

        // 2) res_unittipocaracteristica.restringido_en_resumen
        if ($schema->hasTable('res_unittipocaracteristica')) {
            $t = $schema->getTable('res_unittipocaracteristica');
            if (!$t->hasColumn('restringido_en_resumen')) {
                $this->addSql("ALTER TABLE res_unittipocaracteristica ADD restringido_en_resumen TINYINT(1) NOT NULL DEFAULT 0");
            }
            // Si existiera un campo viejo (por intentos previos), migramos y limpiamos con seguridad
            if ($t->hasColumn('visible_en_resumen_publico')) {
                $this->addSql("UPDATE res_unittipocaracteristica SET restringido_en_resumen = visible_en_resumen_publico");
                $this->addSql("ALTER TABLE res_unittipocaracteristica DROP COLUMN visible_en_resumen_publico");
            }
        }

        // 3) (opcional) elimina un flag antiguo a nivel característica si quedó de pruebas
        if ($schema->hasTable('res_unitcaracteristica')) {
            $t = $schema->getTable('res_unitcaracteristica');
            if ($t->hasColumn('restringida_en_resumen')) {
                $this->addSql("ALTER TABLE res_unitcaracteristica DROP COLUMN restringida_en_resumen");
            }
        }
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform()->getName();
        $this->abortIf($platform !== 'mysql' && $platform !== 'mariadb', sprintf('This migration is for MySQL/MariaDB only. Detected: %s', $platform));

        // revertir campos añadidos
        if ($schema->hasTable('res_unittipocaracteristica')) {
            $t = $schema->getTable('res_unittipocaracteristica');
            if ($t->hasColumn('restringido_en_resumen')) {
                $this->addSql("ALTER TABLE res_unittipocaracteristica DROP COLUMN restringido_en_resumen");
            }
            // si quieres recrear el viejo por compat, descomenta:
            // $this->addSql("ALTER TABLE res_unittipocaracteristica ADD visible_en_resumen_publico TINYINT(1) NOT NULL DEFAULT 0");
        }

        if ($schema->hasTable('res_estado')) {
            $t = $schema->getTable('res_estado');
            if ($t->hasColumn('habilitar_resumen_publico')) {
                $this->addSql("ALTER TABLE res_estado DROP COLUMN habilitar_resumen_publico");
            }
        }
    }
}
