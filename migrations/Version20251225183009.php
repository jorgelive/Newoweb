<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251225183009 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_evento_calendario_queue DROP FOREIGN KEY FK_EDED1BCD34942823');
        $this->addSql('DROP INDEX IDX_EDED1BCD34942823 ON pms_evento_calendario_queue');
        $this->addSql('ALTER TABLE pms_evento_calendario_queue ADD link_id_original INT DEFAULT NULL, DROP unidad_beds24_id, CHANGE evento_id_original link_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE pms_evento_calendario_queue ADD CONSTRAINT FK_EDED1BCDADA40271 FOREIGN KEY (link_id) REFERENCES pms_evento_beds24_link (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_EDED1BCDADA40271 ON pms_evento_calendario_queue (link_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_evento_calendario_queue DROP FOREIGN KEY FK_EDED1BCDADA40271');
        $this->addSql('DROP INDEX IDX_EDED1BCDADA40271 ON pms_evento_calendario_queue');
        $this->addSql('ALTER TABLE pms_evento_calendario_queue ADD unidad_beds24_id INT NOT NULL, ADD evento_id_original INT DEFAULT NULL, DROP link_id, DROP link_id_original');
        $this->addSql('ALTER TABLE pms_evento_calendario_queue ADD CONSTRAINT FK_EDED1BCD34942823 FOREIGN KEY (unidad_beds24_id) REFERENCES pms_unidad_beds24_map (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('CREATE INDEX IDX_EDED1BCD34942823 ON pms_evento_calendario_queue (unidad_beds24_id)');
    }
}
