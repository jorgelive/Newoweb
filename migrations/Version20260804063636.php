<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260804063636 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE cot_file CHANGE token token VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE pms_catalogo ADD sobreescribir_traduccion TINYINT(1) DEFAULT 0 NOT NULL, DROP auto_translate, CHANGE id id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', CHANGE unidad_id unidad_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE pms_catalogo RENAME INDEX uniq_catalogo_unidad TO UNIQ_F6C498B49D01464C');
        $this->addSql('ALTER TABLE pms_catalogo_has_item CHANGE id id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', CHANGE catalogo_id catalogo_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', CHANGE item_id item_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE pms_catalogo_has_item RENAME INDEX idx_catalogo TO IDX_7EF3C4684979D753');
        $this->addSql('ALTER TABLE pms_catalogo_has_item RENAME INDEX idx_item TO IDX_7EF3C468126F525E');
        $this->addSql('ALTER TABLE pms_establecimiento RENAME INDEX uniq_pms_establecimiento_slug TO UNIQ_844C87D2989D9B62');
        $this->addSql('ALTER TABLE pms_unidad RENAME INDEX uniq_pms_unidad_slug TO UNIQ_D89F5BC989D9B62');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE cot_file CHANGE token token VARCHAR(20) NOT NULL');
        $this->addSql('ALTER TABLE pms_catalogo ADD auto_translate TINYINT(1) DEFAULT 1 NOT NULL, DROP sobreescribir_traduccion, CHANGE id id BINARY(16) NOT NULL, CHANGE unidad_id unidad_id BINARY(16) NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE pms_catalogo RENAME INDEX uniq_f6c498b49d01464c TO uniq_catalogo_unidad');
        $this->addSql('ALTER TABLE pms_catalogo_has_item CHANGE id id BINARY(16) NOT NULL, CHANGE catalogo_id catalogo_id BINARY(16) NOT NULL, CHANGE item_id item_id BINARY(16) NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE pms_catalogo_has_item RENAME INDEX idx_7ef3c4684979d753 TO idx_catalogo');
        $this->addSql('ALTER TABLE pms_catalogo_has_item RENAME INDEX idx_7ef3c468126f525e TO idx_item');
        $this->addSql('ALTER TABLE pms_establecimiento RENAME INDEX uniq_844c87d2989d9b62 TO uniq_pms_establecimiento_slug');
        $this->addSql('ALTER TABLE pms_unidad RENAME INDEX uniq_d89f5bc989d9b62 TO uniq_pms_unidad_slug');
    }
}
