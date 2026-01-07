<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251215005407 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_evento_calendario ADD unidad_beds24_map_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE pms_evento_calendario ADD CONSTRAINT FK_7348A9BC304A02E7 FOREIGN KEY (unidad_beds24_map_id) REFERENCES pms_unidad_beds24_map (id)');
        $this->addSql('CREATE INDEX IDX_7348A9BC304A02E7 ON pms_evento_calendario (unidad_beds24_map_id)');
        $this->addSql('ALTER TABLE pms_reserva DROP FOREIGN KEY FK_717583E0304A02E7');
        $this->addSql('DROP INDEX IDX_717583E0304A02E7 ON pms_reserva');
        $this->addSql('ALTER TABLE pms_reserva DROP unidad_beds24_map_id');
        $this->addSql('ALTER TABLE pms_unidad_beds24_map ADD beds24_config_id INT NOT NULL');
        $this->addSql('ALTER TABLE pms_unidad_beds24_map ADD CONSTRAINT FK_DA9030BE5BC0574C FOREIGN KEY (beds24_config_id) REFERENCES pms_beds24_config (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_DA9030BE5BC0574C ON pms_unidad_beds24_map (beds24_config_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_evento_calendario DROP FOREIGN KEY FK_7348A9BC304A02E7');
        $this->addSql('DROP INDEX IDX_7348A9BC304A02E7 ON pms_evento_calendario');
        $this->addSql('ALTER TABLE pms_evento_calendario DROP unidad_beds24_map_id');
        $this->addSql('ALTER TABLE pms_reserva ADD unidad_beds24_map_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE pms_reserva ADD CONSTRAINT FK_717583E0304A02E7 FOREIGN KEY (unidad_beds24_map_id) REFERENCES pms_unidad_beds24_map (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('CREATE INDEX IDX_717583E0304A02E7 ON pms_reserva (unidad_beds24_map_id)');
        $this->addSql('ALTER TABLE pms_unidad_beds24_map DROP FOREIGN KEY FK_DA9030BE5BC0574C');
        $this->addSql('DROP INDEX IDX_DA9030BE5BC0574C ON pms_unidad_beds24_map');
        $this->addSql('ALTER TABLE pms_unidad_beds24_map DROP beds24_config_id');
    }
}
