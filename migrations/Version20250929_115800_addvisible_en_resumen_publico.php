<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250929_115800_addvisible_en_resumen_publico extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add flags: res_estado.habilitar_resumen_publico and res_unittipocaracteristica.visible_en_resumen_publico';
    }

    public function up(Schema $schema): void
    {
        // res_estado
        $this->addSql("ALTER TABLE res_estado ADD habilitar_resumen_publico TINYINT(1) NOT NULL DEFAULT 0");

        // res_unittipocaracteristica
        $this->addSql("ALTER TABLE res_unittipocaracteristica ADD visible_en_resumen_publico TINYINT(1) NOT NULL DEFAULT 0");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE res_estado DROP COLUMN habilitar_resumen_publico");
        $this->addSql("ALTER TABLE res_unittipocaracteristica DROP COLUMN visible_en_resumen_publico");
    }
}
