<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260813124940 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pasos del asistente por ítem de guía: lo que se dice sólo si la primera respuesta no resolvió.';
    }

    public function up(Schema $schema): void
    {
        // Sólo la columna nueva. El DROP de `pms_evento_calendario_backup_20260808` que generó
        // el diff se ha quitado a mano: esa tabla es un respaldo y se borra cuando se decida,
        // no de rebote en una migración que va de otra cosa.
        $this->addSql('ALTER TABLE pms_guia_item ADD agente_pasos JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_guia_item DROP agente_pasos');
    }
}
