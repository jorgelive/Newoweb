<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251225190525 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE pms_beds24_link_queue (id INT AUTO_INCREMENT NOT NULL, link_id INT DEFAULT NULL, endpoint_id INT NOT NULL, link_id_original INT DEFAULT NULL, needs_sync TINYINT(1) DEFAULT 1 NOT NULL, status VARCHAR(20) DEFAULT \'pending\' NOT NULL, next_retry_at DATETIME DEFAULT NULL, locked_at DATETIME DEFAULT NULL, locked_by VARCHAR(64) DEFAULT NULL, retry_count SMALLINT DEFAULT 0 NOT NULL, last_http_code SMALLINT DEFAULT NULL, dedupe_key VARCHAR(120) DEFAULT NULL, payload_hash VARCHAR(64) DEFAULT NULL, last_sync DATETIME DEFAULT NULL, last_status VARCHAR(30) DEFAULT NULL, last_message VARCHAR(255) DEFAULT NULL, last_request_json LONGTEXT DEFAULT NULL, last_response_json LONGTEXT DEFAULT NULL, created DATETIME NOT NULL, updated DATETIME NOT NULL, INDEX IDX_5B58F001ADA40271 (link_id), INDEX IDX_5B58F00121AF7E36 (endpoint_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE pms_beds24_link_queue ADD CONSTRAINT FK_5B58F001ADA40271 FOREIGN KEY (link_id) REFERENCES pms_evento_beds24_link (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE pms_beds24_link_queue ADD CONSTRAINT FK_5B58F00121AF7E36 FOREIGN KEY (endpoint_id) REFERENCES pms_beds24_endpoint (id)');
        $this->addSql('ALTER TABLE pms_evento_calendario_queue DROP FOREIGN KEY FK_EDED1BCD21AF7E36');
        $this->addSql('ALTER TABLE pms_evento_calendario_queue DROP FOREIGN KEY FK_EDED1BCD87A5F842');
        $this->addSql('ALTER TABLE pms_evento_calendario_queue DROP FOREIGN KEY FK_EDED1BCDADA40271');
        $this->addSql('DROP TABLE pms_evento_calendario_queue');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE pms_evento_calendario_queue (id INT AUTO_INCREMENT NOT NULL, evento_id INT DEFAULT NULL, endpoint_id INT NOT NULL, link_id INT DEFAULT NULL, needs_sync TINYINT(1) DEFAULT 1 NOT NULL, retry_count SMALLINT DEFAULT 0 NOT NULL, last_sync DATETIME DEFAULT NULL, last_status VARCHAR(30) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, last_message VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, last_request_json LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, last_response_json LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, created DATETIME NOT NULL, updated DATETIME NOT NULL, status VARCHAR(20) CHARACTER SET utf8mb4 DEFAULT \'pending\' NOT NULL COLLATE `utf8mb4_unicode_ci`, next_retry_at DATETIME DEFAULT NULL, locked_at DATETIME DEFAULT NULL, locked_by VARCHAR(64) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, last_http_code SMALLINT DEFAULT NULL, dedupe_key VARCHAR(120) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, payload_hash VARCHAR(64) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, link_id_original INT DEFAULT NULL, INDEX IDX_EDED1BCD87A5F842 (evento_id), INDEX IDX_EDED1BCD21AF7E36 (endpoint_id), INDEX IDX_EDED1BCDADA40271 (link_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE pms_evento_calendario_queue ADD CONSTRAINT FK_EDED1BCD21AF7E36 FOREIGN KEY (endpoint_id) REFERENCES pms_beds24_endpoint (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('ALTER TABLE pms_evento_calendario_queue ADD CONSTRAINT FK_EDED1BCD87A5F842 FOREIGN KEY (evento_id) REFERENCES pms_evento_calendario (id) ON UPDATE NO ACTION ON DELETE SET NULL');
        $this->addSql('ALTER TABLE pms_evento_calendario_queue ADD CONSTRAINT FK_EDED1BCDADA40271 FOREIGN KEY (link_id) REFERENCES pms_evento_beds24_link (id) ON UPDATE NO ACTION ON DELETE SET NULL');
        $this->addSql('ALTER TABLE pms_beds24_link_queue DROP FOREIGN KEY FK_5B58F001ADA40271');
        $this->addSql('ALTER TABLE pms_beds24_link_queue DROP FOREIGN KEY FK_5B58F00121AF7E36');
        $this->addSql('DROP TABLE pms_beds24_link_queue');
    }
}
