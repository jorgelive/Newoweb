<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `subeje` pasa de NULL a cadena vacía, y el índice único vuelve a proteger.
 *
 * ⚠️ En InnoDB **un índice único admite cuantos `NULL` quiera**. Al meter `subeje` en
 * `uniq_file_grupo_tipo_clave` dejándolo nullable, todo grupo sin tramo —habitaciones, grupos,
 * servicios: casi todo lo que existe— quedó FUERA de la unicidad que tenía antes. Un doble POST al
 * alta de subgrupos creaba dos «HA13» en silencio.
 *
 * Con `NOT NULL DEFAULT ''` la fila entra en el índice y la protección vuelve.
 */
final class Version20260824070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'cotizacion_file_grupo.subeje NOT NULL: los NULL se salían del índice único.';
    }

    public function up(Schema $schema): void
    {
        // El índice fuera antes de tocar la columna: si hubiera duplicados con NULL creados
        // mientras estuvo abierta la puerta, esto los deja ver al recrearlo en vez de fallar aquí.
        $this->addSql('DROP INDEX uniq_file_grupo_tipo_clave ON cotizacion_file_grupo');
        $this->addSql("UPDATE cotizacion_file_grupo SET subeje = '' WHERE subeje IS NULL");
        $this->addSql("ALTER TABLE cotizacion_file_grupo CHANGE subeje subeje VARCHAR(60) DEFAULT '' NOT NULL");
        $this->addSql('CREATE UNIQUE INDEX uniq_file_grupo_tipo_clave ON cotizacion_file_grupo (file_id, tipo, subeje, clave)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_file_grupo CHANGE subeje subeje VARCHAR(60) DEFAULT NULL');
    }
}
