<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260103012137 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_tarifa_queue DROP FOREIGN KEY FK_FE31F6612EC01F0F');
        $this->addSql('ALTER TABLE pms_tarifa_queue CHANGE tarifa_rango_id tarifa_rango_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE pms_tarifa_queue ADD CONSTRAINT FK_FE31F6612EC01F0F FOREIGN KEY (tarifa_rango_id) REFERENCES pms_tarifa_rango (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_tarifa_queue DROP FOREIGN KEY FK_FE31F6612EC01F0F');
        $this->addSql('ALTER TABLE pms_tarifa_queue CHANGE tarifa_rango_id tarifa_rango_id INT NOT NULL');
        $this->addSql('ALTER TABLE pms_tarifa_queue ADD CONSTRAINT FK_FE31F6612EC01F0F FOREIGN KEY (tarifa_rango_id) REFERENCES pms_tarifa_rango (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
    }
}
