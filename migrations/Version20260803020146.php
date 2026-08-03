<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Flag de vigencia financiera de la reserva.
 *
 * `activa = false` deja de sumar los cargos de la estancia y hace que sólo cuente la
 * PENALIZACIÓN (el "Cancel Fee"). Se separa del estado de Beds24 a propósito: un huésped que
 * negocia pasarse a reserva DIRECTA cancela en la OTA, y ahí la estancia sí ocurre y hay que
 * cobrarla. Los cargos nunca se borran — sólo dejan de computar (§12.7).
 *
 * Arranca en TRUE para todas las filas existentes: es el estado correcto de una reserva viva,
 * y el listener bajará el flag en cuanto detecte una cancelación real.
 */
final class Version20260803020146 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade pms_informacion_financiera.activa: si es false sólo computa la penalización, no los cargos de la estancia.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_informacion_financiera ADD activa TINYINT(1) DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_informacion_financiera DROP activa');
    }
}
