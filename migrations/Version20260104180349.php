<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260104180349 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE pms_beds24_webhook_audit (id INT AUTO_INCREMENT NOT NULL, received_at DATETIME NOT NULL, event_type VARCHAR(80) DEFAULT NULL, remote_ip VARCHAR(64) DEFAULT NULL, headers_json JSON DEFAULT NULL, payload_raw LONGTEXT NOT NULL, payload_json JSON DEFAULT NULL, status VARCHAR(20) DEFAULT \'received\' NOT NULL, error_message LONGTEXT DEFAULT NULL, processing_meta JSON DEFAULT NULL, updated DATETIME DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE pms_reserva_webhook_log DROP FOREIGN KEY FK_7CF639BAD67139E8');
        $this->addSql('DROP TABLE pms_reserva_webhook_log');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE pms_reserva_webhook_log (id INT AUTO_INCREMENT NOT NULL, reserva_id INT NOT NULL, payload JSON NOT NULL, estado_beds24_snapshot VARCHAR(50) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, origen VARCHAR(50) CHARACTER SET utf8mb4 DEFAULT \'webhook\' NOT NULL COLLATE `utf8mb4_unicode_ci`, created DATETIME NOT NULL, INDEX IDX_7CF639BAD67139E8 (reserva_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE pms_reserva_webhook_log ADD CONSTRAINT FK_7CF639BAD67139E8 FOREIGN KEY (reserva_id) REFERENCES pms_reserva (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('DROP TABLE pms_beds24_webhook_audit');
    }
}
