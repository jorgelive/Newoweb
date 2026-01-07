<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251218023115 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_reserva DROP FOREIGN KEY FK_717583E0B77634D2');
        $this->addSql('DROP INDEX IDX_717583E0B77634D2 ON pms_reserva');
        $this->addSql('ALTER TABLE pms_reserva DROP moneda_id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_reserva ADD moneda_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE pms_reserva ADD CONSTRAINT FK_717583E0B77634D2 FOREIGN KEY (moneda_id) REFERENCES mae_moneda (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('CREATE INDEX IDX_717583E0B77634D2 ON pms_reserva (moneda_id)');
    }
}
