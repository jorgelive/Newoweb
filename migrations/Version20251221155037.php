<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251221155037 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_evento_calendario DROP FOREIGN KEY FK_7348A9BC304A02E7');
        $this->addSql('DROP INDEX IDX_7348A9BC304A02E7 ON pms_evento_calendario');
        $this->addSql('ALTER TABLE pms_evento_calendario DROP unidad_beds24_map_id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_evento_calendario ADD unidad_beds24_map_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE pms_evento_calendario ADD CONSTRAINT FK_7348A9BC304A02E7 FOREIGN KEY (unidad_beds24_map_id) REFERENCES pms_unidad_beds24_map (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('CREATE INDEX IDX_7348A9BC304A02E7 ON pms_evento_calendario (unidad_beds24_map_id)');
    }
}
