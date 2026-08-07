<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260806211620 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Móvil del usuario: lo identifica al escribir por WhatsApp (dígitos sin +, único).';
    }

    public function up(Schema $schema): void
    {
        // Único para que dos personas no compartan identidad en el chat; nullable porque
        // MySQL admite varios NULL y no todo el equipo tiene número registrado.
        $this->addSql('ALTER TABLE user ADD telefono VARCHAR(30) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649C1E70A7F ON user (telefono)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_8D93D649C1E70A7F ON user');
        $this->addSql('ALTER TABLE user DROP telefono');
    }
}
