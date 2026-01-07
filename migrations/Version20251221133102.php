<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251221133102 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE pms_evento_beds24_link (id INT AUTO_INCREMENT NOT NULL, evento_id INT NOT NULL, unidad_beds24_map_id INT NOT NULL, origin_link_id INT DEFAULT NULL, beds24_book_id BIGINT DEFAULT NULL, last_seen_at DATETIME DEFAULT NULL, created DATETIME NOT NULL, updated DATETIME NOT NULL, UNIQUE INDEX UNIQ_5C989963FB5376C5 (beds24_book_id), INDEX IDX_5C98996387A5F842 (evento_id), INDEX IDX_5C989963304A02E7 (unidad_beds24_map_id), INDEX IDX_5C98996318D3171C (origin_link_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE pms_evento_beds24_link ADD CONSTRAINT FK_5C98996387A5F842 FOREIGN KEY (evento_id) REFERENCES pms_evento_calendario (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE pms_evento_beds24_link ADD CONSTRAINT FK_5C989963304A02E7 FOREIGN KEY (unidad_beds24_map_id) REFERENCES pms_unidad_beds24_map (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE pms_evento_beds24_link ADD CONSTRAINT FK_5C98996318D3171C FOREIGN KEY (origin_link_id) REFERENCES pms_evento_beds24_link (id) ON DELETE SET NULL');
        $this->addSql('DROP INDEX UNIQ_7348A9BCFB5376C5 ON pms_evento_calendario');
        $this->addSql('ALTER TABLE pms_evento_calendario DROP beds24_book_id, DROP beds24_reference');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_evento_beds24_link DROP FOREIGN KEY FK_5C98996387A5F842');
        $this->addSql('ALTER TABLE pms_evento_beds24_link DROP FOREIGN KEY FK_5C989963304A02E7');
        $this->addSql('ALTER TABLE pms_evento_beds24_link DROP FOREIGN KEY FK_5C98996318D3171C');
        $this->addSql('DROP TABLE pms_evento_beds24_link');
        $this->addSql('ALTER TABLE pms_evento_calendario ADD beds24_book_id BIGINT DEFAULT NULL, ADD beds24_reference VARCHAR(100) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7348A9BCFB5376C5 ON pms_evento_calendario (beds24_book_id)');
    }
}
