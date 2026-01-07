<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251227171518 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_beds24_link_queue ADD beds24_config_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE pms_beds24_link_queue ADD CONSTRAINT FK_5B58F0015BC0574C FOREIGN KEY (beds24_config_id) REFERENCES pms_beds24_config (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_5B58F0015BC0574C ON pms_beds24_link_queue (beds24_config_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_beds24_link_queue DROP FOREIGN KEY FK_5B58F0015BC0574C');
        $this->addSql('DROP INDEX IDX_5B58F0015BC0574C ON pms_beds24_link_queue');
        $this->addSql('ALTER TABLE pms_beds24_link_queue DROP beds24_config_id');
    }
}
