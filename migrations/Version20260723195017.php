<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260723195017 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // Backfill: las filas existentes tienen NULL antes de volver la columna NOT NULL
        $this->addSql('UPDATE cotizacion_cotizacion SET precio_desde_moneda = \'USD\' WHERE precio_desde_moneda IS NULL');
        $this->addSql('ALTER TABLE cotizacion_cotizacion CHANGE precio_desde_moneda precio_desde_moneda VARCHAR(10) DEFAULT \'USD\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE cotizacion_cotizacion CHANGE precio_desde_moneda precio_desde_moneda VARCHAR(10) DEFAULT NULL');
    }
}
