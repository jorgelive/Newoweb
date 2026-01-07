<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251216190406 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_beds24_config ADD refresh_token VARCHAR(512) DEFAULT NULL, ADD auth_token VARCHAR(512) DEFAULT NULL, ADD auth_token_expires_at DATETIME DEFAULT NULL, DROP prop_key, DROP prop_id');
        $this->addSql('ALTER TABLE pms_unidad_beds24_map CHANGE beds24_property_id beds24_property_id INT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_beds24_config ADD prop_key VARCHAR(255) DEFAULT NULL, ADD prop_id INT NOT NULL, DROP refresh_token, DROP auth_token, DROP auth_token_expires_at');
        $this->addSql('ALTER TABLE pms_unidad_beds24_map CHANGE beds24_property_id beds24_property_id INT DEFAULT NULL');
    }
}
