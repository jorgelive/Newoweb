<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260723192451 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE cotizacion_catalogo (id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', nombre VARCHAR(150) NOT NULL, tipo_cliente VARCHAR(30) DEFAULT \'economico\' NOT NULL, idioma_cliente VARCHAR(5) DEFAULT \'es\' NOT NULL, activo TINYINT(1) DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', localizador VARCHAR(12) NOT NULL, UNIQUE INDEX UNIQ_A5C4967D28AFE325 (localizador), INDEX idx_cotizacion_catalogo_created_at (created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE cotizacion_cotizacion ADD catalogo_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\', ADD precio_desde NUMERIC(12, 2) DEFAULT NULL, ADD precio_desde_moneda VARCHAR(10) DEFAULT NULL, CHANGE file_id file_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE cotizacion_cotizacion ADD CONSTRAINT FK_90369C314979D753 FOREIGN KEY (catalogo_id) REFERENCES cotizacion_catalogo (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_90369C314979D753 ON cotizacion_cotizacion (catalogo_id)');
        $this->addSql('CREATE INDEX idx_cotizacion_catalogo_version ON cotizacion_cotizacion (catalogo_id, version)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE cotizacion_cotizacion DROP FOREIGN KEY FK_90369C314979D753');
        $this->addSql('DROP TABLE cotizacion_catalogo');
        $this->addSql('DROP INDEX IDX_90369C314979D753 ON cotizacion_cotizacion');
        $this->addSql('DROP INDEX idx_cotizacion_catalogo_version ON cotizacion_cotizacion');
        $this->addSql('ALTER TABLE cotizacion_cotizacion DROP catalogo_id, DROP precio_desde, DROP precio_desde_moneda, CHANGE file_id file_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\'');
    }
}
