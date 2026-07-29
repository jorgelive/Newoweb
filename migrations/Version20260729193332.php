<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260729193332 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade itinerario_maestro_id a cotizacion_cotservicio (re-sync exacto de flags de plantilla).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_cotservicio ADD itinerario_maestro_id VARCHAR(36) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_cotservicio DROP itinerario_maestro_id');
    }
}
