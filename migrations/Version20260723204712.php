<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260723204712 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        // JSON no admite DEFAULT en MySQL: se agrega nullable, se rellena y se endurece
        $this->addSql('ALTER TABLE cotizacion_cotizacion ADD titulo JSON DEFAULT NULL');
        $this->addSql('UPDATE cotizacion_cotizacion SET titulo = \'[]\' WHERE titulo IS NULL');
        $this->addSql('ALTER TABLE cotizacion_cotizacion MODIFY titulo JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE cotizacion_cotizacion DROP titulo');
    }
}
