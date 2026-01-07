<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260101231059 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE pms_tarifa_queue_delivery (id INT AUTO_INCREMENT NOT NULL, pms_tarifa_queue_id INT NOT NULL, pms_unidad_beds24_map_id INT NOT NULL, beds24_config_id INT NOT NULL, needs_sync TINYINT(1) DEFAULT 1 NOT NULL, status VARCHAR(20) DEFAULT \'pending\' NOT NULL, failed_reason VARCHAR(32) DEFAULT NULL, next_retry_at DATETIME DEFAULT NULL, locked_at DATETIME DEFAULT NULL, processing_started_at DATETIME DEFAULT NULL, locked_by VARCHAR(64) DEFAULT NULL, retry_count SMALLINT DEFAULT 0 NOT NULL, last_http_code SMALLINT DEFAULT NULL, dedupe_key VARCHAR(120) DEFAULT NULL, payload_hash VARCHAR(64) DEFAULT NULL, last_sync DATETIME DEFAULT NULL, last_message VARCHAR(255) DEFAULT NULL, last_request_json LONGTEXT DEFAULT NULL, last_response_json LONGTEXT DEFAULT NULL, created DATETIME NOT NULL, updated DATETIME NOT NULL, INDEX IDX_4AD1F7D53C6C22D8 (pms_tarifa_queue_id), INDEX IDX_4AD1F7D586C8B347 (pms_unidad_beds24_map_id), INDEX IDX_4AD1F7D55BC0574C (beds24_config_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE pms_tarifa_queue_delivery ADD CONSTRAINT FK_4AD1F7D53C6C22D8 FOREIGN KEY (pms_tarifa_queue_id) REFERENCES pms_tarifa_queue (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE pms_tarifa_queue_delivery ADD CONSTRAINT FK_4AD1F7D586C8B347 FOREIGN KEY (pms_unidad_beds24_map_id) REFERENCES pms_unidad_beds24_map (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE pms_tarifa_queue_delivery ADD CONSTRAINT FK_4AD1F7D55BC0574C FOREIGN KEY (beds24_config_id) REFERENCES pms_beds24_config (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE pms_tarifa_queue DROP retry_count, DROP last_sync, DROP last_status, DROP last_message, DROP last_request_json, DROP last_response_json, DROP next_retry_at, DROP locked_at, DROP locked_by, DROP last_http_code, DROP payload_hash');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_tarifa_queue_delivery DROP FOREIGN KEY FK_4AD1F7D53C6C22D8');
        $this->addSql('ALTER TABLE pms_tarifa_queue_delivery DROP FOREIGN KEY FK_4AD1F7D586C8B347');
        $this->addSql('ALTER TABLE pms_tarifa_queue_delivery DROP FOREIGN KEY FK_4AD1F7D55BC0574C');
        $this->addSql('DROP TABLE pms_tarifa_queue_delivery');
        $this->addSql('ALTER TABLE pms_tarifa_queue ADD retry_count SMALLINT DEFAULT 0 NOT NULL, ADD last_sync DATETIME DEFAULT NULL, ADD last_status VARCHAR(30) DEFAULT NULL, ADD last_message VARCHAR(255) DEFAULT NULL, ADD last_request_json LONGTEXT DEFAULT NULL, ADD last_response_json LONGTEXT DEFAULT NULL, ADD next_retry_at DATETIME DEFAULT NULL, ADD locked_at DATETIME DEFAULT NULL, ADD locked_by VARCHAR(64) DEFAULT NULL, ADD last_http_code SMALLINT DEFAULT NULL, ADD payload_hash VARCHAR(64) DEFAULT NULL');
    }
}
