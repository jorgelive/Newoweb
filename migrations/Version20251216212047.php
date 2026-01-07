<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251216212047 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_reserva ADD pais_id INT DEFAULT NULL, ADD apellido_cliente VARCHAR(180) DEFAULT NULL, ADD cantidad_adultos INT DEFAULT NULL, ADD cantidad_ninos INT DEFAULT NULL, ADD beds24_sub_status VARCHAR(50) DEFAULT NULL, ADD fecha_reserva_canal DATETIME DEFAULT NULL, ADD fecha_modificacion_canal DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE pms_reserva ADD CONSTRAINT FK_717583E0C604D5C6 FOREIGN KEY (pais_id) REFERENCES mae_pais (id)');
        $this->addSql('CREATE INDEX IDX_717583E0C604D5C6 ON pms_reserva (pais_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pms_reserva DROP FOREIGN KEY FK_717583E0C604D5C6');
        $this->addSql('DROP INDEX IDX_717583E0C604D5C6 ON pms_reserva');
        $this->addSql('ALTER TABLE pms_reserva DROP pais_id, DROP apellido_cliente, DROP cantidad_adultos, DROP cantidad_ninos, DROP beds24_sub_status, DROP fecha_reserva_canal, DROP fecha_modificacion_canal');
    }
}
