<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cargos financieros manuales: `beds24_item_id` pasa a ser NULL-able. NULL identifica a un
 * cargo creado a mano por el operador (reservas directas, que no reciben invoiceItems del
 * canal). MySQL admite varios NULL en el índice único `uq_cargo_info_item`, así que el
 * dedupe de los cargos sincronizados desde Beds24 se mantiene intacto.
 */
final class Version20260802234033 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Permite cargos financieros manuales (pms_cargo_financiero.beds24_item_id NULL-able).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_cargo_financiero CHANGE beds24_item_id beds24_item_id VARCHAR(50) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Los cargos manuales no tienen equivalente en el esquema anterior: se eliminan
        // antes de restaurar el NOT NULL, o el ALTER fallaría.
        $this->addSql('DELETE FROM pms_cargo_financiero WHERE beds24_item_id IS NULL');
        $this->addSql('ALTER TABLE pms_cargo_financiero CHANGE beds24_item_id beds24_item_id VARCHAR(50) NOT NULL');
    }
}
