<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260102041907 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_unidad ADD tarifa_base_moneda_id INT DEFAULT NULL, ADD tarifa_base_precio NUMERIC(10, 2) DEFAULT NULL, ADD tarifa_base_min_stay SMALLINT DEFAULT 2, ADD tarifa_base_activa TINYINT(1) DEFAULT 1');
        $this->addSql('ALTER TABLE pms_unidad ADD CONSTRAINT FK_D89F5BC6EE900F3 FOREIGN KEY (tarifa_base_moneda_id) REFERENCES mae_moneda (id)');
        $this->addSql('CREATE INDEX IDX_D89F5BC6EE900F3 ON pms_unidad (tarifa_base_moneda_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_unidad DROP FOREIGN KEY FK_D89F5BC6EE900F3');
        $this->addSql('DROP INDEX IDX_D89F5BC6EE900F3 ON pms_unidad');
        $this->addSql('ALTER TABLE pms_unidad DROP tarifa_base_moneda_id, DROP tarifa_base_precio, DROP tarifa_base_min_stay, DROP tarifa_base_activa');
    }
}
