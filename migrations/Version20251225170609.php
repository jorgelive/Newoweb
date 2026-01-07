<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251225170609 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_evento_calendario_queue DROP FOREIGN KEY FK_EDED1BCD87A5F842');
        $this->addSql('ALTER TABLE pms_evento_calendario_queue ADD evento_id_original INT DEFAULT NULL, CHANGE evento_id evento_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE pms_evento_calendario_queue ADD CONSTRAINT FK_EDED1BCD87A5F842 FOREIGN KEY (evento_id) REFERENCES pms_evento_calendario (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_evento_calendario_queue DROP FOREIGN KEY FK_EDED1BCD87A5F842');
        $this->addSql('ALTER TABLE pms_evento_calendario_queue DROP evento_id_original, CHANGE evento_id evento_id INT NOT NULL');
        $this->addSql('ALTER TABLE pms_evento_calendario_queue ADD CONSTRAINT FK_EDED1BCD87A5F842 FOREIGN KEY (evento_id) REFERENCES pms_evento_calendario (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
    }
}
