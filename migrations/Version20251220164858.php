<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251220164858 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_evento_calendario CHANGE estado_id estado_id INT NOT NULL');
        $this->addSql('ALTER TABLE pms_reserva ADD comentarios_huesped LONGTEXT DEFAULT NULL, ADD hora_llegada_canal VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_evento_calendario CHANGE estado_id estado_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE pms_reserva DROP comentarios_huesped, DROP hora_llegada_canal');
    }
}
