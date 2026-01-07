<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251218222159 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_reserva DROP FOREIGN KEY FK_717583E09F5A440B');
        $this->addSql('CREATE TABLE pms_evento_estado (id INT AUTO_INCREMENT NOT NULL, codigo VARCHAR(50) NOT NULL, nombre VARCHAR(100) NOT NULL, color VARCHAR(7) DEFAULT NULL, codigo_beds24 VARCHAR(50) DEFAULT NULL, es_final TINYINT(1) DEFAULT 0 NOT NULL, orden INT DEFAULT NULL, created DATETIME NOT NULL, updated DATETIME NOT NULL, UNIQUE INDEX UNIQ_2B6E859E20332D99 (codigo), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('DROP TABLE pms_reserva_estado');
        $this->addSql('ALTER TABLE pms_evento_calendario ADD estado_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE pms_evento_calendario ADD CONSTRAINT FK_7348A9BC9F5A440B FOREIGN KEY (estado_id) REFERENCES pms_evento_estado (id)');
        $this->addSql('CREATE INDEX IDX_7348A9BC9F5A440B ON pms_evento_calendario (estado_id)');
        $this->addSql('DROP INDEX IDX_717583E09F5A440B ON pms_reserva');
        $this->addSql('ALTER TABLE pms_reserva DROP estado_id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_evento_calendario DROP FOREIGN KEY FK_7348A9BC9F5A440B');
        $this->addSql('CREATE TABLE pms_reserva_estado (id INT AUTO_INCREMENT NOT NULL, codigo VARCHAR(50) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, nombre VARCHAR(100) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, color VARCHAR(7) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, codigo_beds24 VARCHAR(50) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, es_final TINYINT(1) DEFAULT 0 NOT NULL, orden INT DEFAULT NULL, created DATETIME NOT NULL, updated DATETIME NOT NULL, UNIQUE INDEX UNIQ_A65B6E4720332D99 (codigo), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('DROP TABLE pms_evento_estado');
        $this->addSql('DROP INDEX IDX_7348A9BC9F5A440B ON pms_evento_calendario');
        $this->addSql('ALTER TABLE pms_evento_calendario DROP estado_id');
        $this->addSql('ALTER TABLE pms_reserva ADD estado_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE pms_reserva ADD CONSTRAINT FK_717583E09F5A440B FOREIGN KEY (estado_id) REFERENCES pms_reserva_estado (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('CREATE INDEX IDX_717583E09F5A440B ON pms_reserva (estado_id)');
    }
}
