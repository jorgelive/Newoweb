<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251218231831 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE pms_evento_estado_pago (id INT AUTO_INCREMENT NOT NULL, codigo VARCHAR(50) NOT NULL, nombre VARCHAR(100) NOT NULL, color VARCHAR(7) DEFAULT NULL, orden INT DEFAULT NULL, created DATETIME NOT NULL, updated DATETIME NOT NULL, UNIQUE INDEX UNIQ_2972EB1E20332D99 (codigo), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE pms_evento_calendario ADD estado_pago_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE pms_evento_calendario ADD CONSTRAINT FK_7348A9BC41AA2C51 FOREIGN KEY (estado_pago_id) REFERENCES pms_evento_estado_pago (id)');
        $this->addSql('CREATE INDEX IDX_7348A9BC41AA2C51 ON pms_evento_calendario (estado_pago_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_evento_calendario DROP FOREIGN KEY FK_7348A9BC41AA2C51');
        $this->addSql('DROP TABLE pms_evento_estado_pago');
        $this->addSql('DROP INDEX IDX_7348A9BC41AA2C51 ON pms_evento_calendario');
        $this->addSql('ALTER TABLE pms_evento_calendario DROP estado_pago_id');
    }
}
