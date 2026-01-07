<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251221030201 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_tarifa_queue ADD status VARCHAR(20) DEFAULT \'pending\' NOT NULL, ADD next_retry_at DATETIME DEFAULT NULL, ADD locked_at DATETIME DEFAULT NULL, ADD locked_by VARCHAR(64) DEFAULT NULL, ADD last_http_code SMALLINT DEFAULT NULL, ADD dedupe_key VARCHAR(120) DEFAULT NULL, ADD payload_hash VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_tarifa_queue DROP status, DROP next_retry_at, DROP locked_at, DROP locked_by, DROP last_http_code, DROP dedupe_key, DROP payload_hash');
    }
}
