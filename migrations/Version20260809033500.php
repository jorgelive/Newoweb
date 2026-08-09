<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260809033500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Finanzas: origen del enlace opcional, para admitir cobros manuales.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE fin_enlace_pago CHANGE origen_tipo origen_tipo VARCHAR(30) DEFAULT NULL, CHANGE origen_id origen_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE fin_enlace_pago CHANGE origen_tipo origen_tipo VARCHAR(30) NOT NULL, CHANGE origen_id origen_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\'');
    }
}
