<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260103204142 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE pms_pull_queue_job (id INT AUTO_INCREMENT NOT NULL, beds24_config_id INT NOT NULL, type VARCHAR(50) NOT NULL, from_at DATE NOT NULL, to_at DATE NOT NULL, run_at DATETIME NOT NULL, status VARCHAR(20) NOT NULL, priority INT NOT NULL, attempts INT NOT NULL, max_attempts INT NOT NULL, locked_at DATETIME DEFAULT NULL, locked_by VARCHAR(100) DEFAULT NULL, payload_computed JSON DEFAULT NULL, response_meta JSON DEFAULT NULL, last_error LONGTEXT DEFAULT NULL, created DATETIME NOT NULL, updated DATETIME NOT NULL, INDEX IDX_D686624D5BC0574C (beds24_config_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE pms_pull_queue_job_unidad (pms_pull_queue_job_id INT NOT NULL, pms_unidad_id INT NOT NULL, INDEX IDX_57AC2E334D13F445 (pms_pull_queue_job_id), INDEX IDX_57AC2E3357AAEC35 (pms_unidad_id), PRIMARY KEY(pms_pull_queue_job_id, pms_unidad_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE pms_pull_queue_job ADD CONSTRAINT FK_D686624D5BC0574C FOREIGN KEY (beds24_config_id) REFERENCES pms_beds24_config (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE pms_pull_queue_job_unidad ADD CONSTRAINT FK_57AC2E334D13F445 FOREIGN KEY (pms_pull_queue_job_id) REFERENCES pms_pull_queue_job (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE pms_pull_queue_job_unidad ADD CONSTRAINT FK_57AC2E3357AAEC35 FOREIGN KEY (pms_unidad_id) REFERENCES pms_unidad (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_pull_queue_job DROP FOREIGN KEY FK_D686624D5BC0574C');
        $this->addSql('ALTER TABLE pms_pull_queue_job_unidad DROP FOREIGN KEY FK_57AC2E334D13F445');
        $this->addSql('ALTER TABLE pms_pull_queue_job_unidad DROP FOREIGN KEY FK_57AC2E3357AAEC35');
        $this->addSql('DROP TABLE pms_pull_queue_job');
        $this->addSql('DROP TABLE pms_pull_queue_job_unidad');
    }
}
