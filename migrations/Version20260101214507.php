<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260101214507 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_tarifa_queue DROP FOREIGN KEY FK_FE31F66134942823');
        $this->addSql('DROP INDEX IDX_FE31F66134942823 ON pms_tarifa_queue');
        $this->addSql('ALTER TABLE pms_tarifa_queue ADD moneda_id INT DEFAULT NULL, ADD fecha_inicio DATE NOT NULL, ADD fecha_fin DATE NOT NULL, ADD precio NUMERIC(10, 2) NOT NULL, ADD min_stay SMALLINT DEFAULT 2 NOT NULL, CHANGE unidad_beds24_id unidad_id INT NOT NULL');
        $this->addSql('ALTER TABLE pms_tarifa_queue ADD CONSTRAINT FK_FE31F6619D01464C FOREIGN KEY (unidad_id) REFERENCES pms_unidad (id)');
        $this->addSql('ALTER TABLE pms_tarifa_queue ADD CONSTRAINT FK_FE31F661B77634D2 FOREIGN KEY (moneda_id) REFERENCES mae_moneda (id)');
        $this->addSql('CREATE INDEX IDX_FE31F6619D01464C ON pms_tarifa_queue (unidad_id)');
        $this->addSql('CREATE INDEX IDX_FE31F661B77634D2 ON pms_tarifa_queue (moneda_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_tarifa_queue DROP FOREIGN KEY FK_FE31F6619D01464C');
        $this->addSql('ALTER TABLE pms_tarifa_queue DROP FOREIGN KEY FK_FE31F661B77634D2');
        $this->addSql('DROP INDEX IDX_FE31F6619D01464C ON pms_tarifa_queue');
        $this->addSql('DROP INDEX IDX_FE31F661B77634D2 ON pms_tarifa_queue');
        $this->addSql('ALTER TABLE pms_tarifa_queue DROP moneda_id, DROP fecha_inicio, DROP fecha_fin, DROP precio, DROP min_stay, CHANGE unidad_id unidad_beds24_id INT NOT NULL');
        $this->addSql('ALTER TABLE pms_tarifa_queue ADD CONSTRAINT FK_FE31F66134942823 FOREIGN KEY (unidad_beds24_id) REFERENCES pms_unidad_beds24_map (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('CREATE INDEX IDX_FE31F66134942823 ON pms_tarifa_queue (unidad_beds24_id)');
    }
}
