<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251219044438 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_reserva ADD idioma_id INT NOT NULL');
        $this->addSql('ALTER TABLE pms_reserva ADD CONSTRAINT FK_717583E0DEDC0611 FOREIGN KEY (idioma_id) REFERENCES mae_idioma (id)');
        $this->addSql('CREATE INDEX IDX_717583E0DEDC0611 ON pms_reserva (idioma_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_reserva DROP FOREIGN KEY FK_717583E0DEDC0611');
        $this->addSql('DROP INDEX IDX_717583E0DEDC0611 ON pms_reserva');
        $this->addSql('ALTER TABLE pms_reserva DROP idioma_id');
    }
}
