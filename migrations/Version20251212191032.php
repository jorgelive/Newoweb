<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251212191032 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE pms_reserva_webhook_log (id INT AUTO_INCREMENT NOT NULL, reserva_id INT NOT NULL, payload JSON NOT NULL, estado_beds24_snapshot VARCHAR(50) DEFAULT NULL, origen VARCHAR(50) DEFAULT \'webhook\' NOT NULL, created DATETIME NOT NULL, INDEX IDX_7CF639BAD67139E8 (reserva_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE pms_reserva_webhook_log ADD CONSTRAINT FK_7CF639BAD67139E8 FOREIGN KEY (reserva_id) REFERENCES pms_reserva (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE pms_channel ADD beds24_channel_id VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE pms_evento_calendario ADD beds24_book_id BIGINT DEFAULT NULL, ADD precio NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL, ADD nombre_huesped VARCHAR(150) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7348A9BCFB5376C5 ON pms_evento_calendario (beds24_book_id)');
        $this->addSql('ALTER TABLE pms_reserva ADD unidad_beds24_map_id INT DEFAULT NULL, ADD beds24_master_id BIGINT DEFAULT NULL, ADD referencia_canal VARCHAR(100) DEFAULT NULL, ADD nota LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE pms_reserva ADD CONSTRAINT FK_717583E0304A02E7 FOREIGN KEY (unidad_beds24_map_id) REFERENCES pms_unidad_beds24_map (id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_717583E06CA88ADE ON pms_reserva (beds24_master_id)');
        $this->addSql('CREATE INDEX IDX_717583E0304A02E7 ON pms_reserva (unidad_beds24_map_id)');
        $this->addSql('ALTER TABLE pms_unidad_beds24_map ADD channel_prop_id VARCHAR(50) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_reserva_webhook_log DROP FOREIGN KEY FK_7CF639BAD67139E8');
        $this->addSql('DROP TABLE pms_reserva_webhook_log');
        $this->addSql('ALTER TABLE pms_channel DROP beds24_channel_id');
        $this->addSql('DROP INDEX UNIQ_7348A9BCFB5376C5 ON pms_evento_calendario');
        $this->addSql('ALTER TABLE pms_evento_calendario DROP beds24_book_id, DROP precio, DROP nombre_huesped');
        $this->addSql('ALTER TABLE pms_reserva DROP FOREIGN KEY FK_717583E0304A02E7');
        $this->addSql('DROP INDEX UNIQ_717583E06CA88ADE ON pms_reserva');
        $this->addSql('DROP INDEX IDX_717583E0304A02E7 ON pms_reserva');
        $this->addSql('ALTER TABLE pms_reserva DROP unidad_beds24_map_id, DROP beds24_master_id, DROP referencia_canal, DROP nota');
        $this->addSql('ALTER TABLE pms_unidad_beds24_map DROP channel_prop_id');
    }
}
