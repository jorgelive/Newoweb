<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La limpieza del horario extra: fuera `evento_origen_id` y el estado `extension`.
 *
 * Fase 6 de docs/PlanHorarioExtraSinEventos.md. Desde la fase 4 (Version20261001180000) no queda
 * ningún evento hermano ni ningún evento en estado `extension`; la noche extra sale de la casilla
 * y su `black` es un link de la estancia. La columna y el estado ya no los usa nadie.
 */
final class Version20261001200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Horario extra: se borran pms_evento_calendario.evento_origen_id y el estado extension.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_evento_calendario DROP FOREIGN KEY FK_7348A9BCAE4AADE3');
        $this->addSql('DROP INDEX IDX_7348A9BCAE4AADE3 ON pms_evento_calendario');
        $this->addSql('ALTER TABLE pms_evento_calendario DROP evento_origen_id');
        $this->addSql("DELETE FROM pms_evento_estado WHERE id = 'extension'");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Los eventos de extensión ya no existen en el código.');
    }
}
