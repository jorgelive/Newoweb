<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251230170748 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_evento_calendario DROP FOREIGN KEY FK_7348A9BCA9276E6C');
        $this->addSql('DROP TABLE pms_evento_calendario_tipo');
        $this->addSql('DROP INDEX IDX_7348A9BCA9276E6C ON pms_evento_calendario');
        $this->addSql('ALTER TABLE pms_evento_calendario DROP tipo_id');
        $this->addSql('ALTER TABLE pms_tarifa_rango ADD min_stay INT DEFAULT 2 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE pms_evento_calendario_tipo (id INT AUTO_INCREMENT NOT NULL, codigo VARCHAR(50) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, nombre VARCHAR(100) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, color VARCHAR(7) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, orden INT DEFAULT NULL, created DATETIME NOT NULL, updated DATETIME NOT NULL, UNIQUE INDEX UNIQ_B2C7288B20332D99 (codigo), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE pms_evento_calendario ADD tipo_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE pms_evento_calendario ADD CONSTRAINT FK_7348A9BCA9276E6C FOREIGN KEY (tipo_id) REFERENCES pms_evento_calendario_tipo (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('CREATE INDEX IDX_7348A9BCA9276E6C ON pms_evento_calendario (tipo_id)');
        $this->addSql('ALTER TABLE pms_tarifa_rango DROP min_stay');
    }
}
