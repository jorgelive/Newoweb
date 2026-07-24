<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260723200624 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // JSON no admite DEFAULT en MySQL: se agrega nullable, se rellena y se endurece
        $this->addSql('ALTER TABLE cotizacion_cotizacion ADD precios_desde JSON DEFAULT NULL, ADD orden INT DEFAULT 0 NOT NULL, DROP precio_desde, DROP precio_desde_moneda');
        $this->addSql('UPDATE cotizacion_cotizacion SET precios_desde = \'[]\' WHERE precios_desde IS NULL');
        $this->addSql('ALTER TABLE cotizacion_cotizacion MODIFY precios_desde JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE cotizacion_cotizacion ADD precio_desde NUMERIC(12, 2) DEFAULT NULL, ADD precio_desde_moneda VARCHAR(10) DEFAULT \'USD\' NOT NULL, DROP precios_desde, DROP orden');
    }
}
