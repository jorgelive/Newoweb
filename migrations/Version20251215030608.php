<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251215030608 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_establecimiento DROP FOREIGN KEY FK_844C87D25BC0574C');
        $this->addSql('DROP INDEX IDX_844C87D25BC0574C ON pms_establecimiento');
        $this->addSql('ALTER TABLE pms_establecimiento DROP beds24_config_id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_establecimiento ADD beds24_config_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE pms_establecimiento ADD CONSTRAINT FK_844C87D25BC0574C FOREIGN KEY (beds24_config_id) REFERENCES pms_beds24_config (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('CREATE INDEX IDX_844C87D25BC0574C ON pms_establecimiento (beds24_config_id)');
    }
}
