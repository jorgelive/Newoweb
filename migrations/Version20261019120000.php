<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `travel_organizacion.es_persona`: un proveedor que es una persona y no una empresa.
 *
 * Decide el saludo de la solicitud de servicio —«Hola, Gabriel Ayme:» en vez de «Estimado equipo
 * de…»—. Ver `TravelOrganizacion::$esPersona`.
 *
 * Se marca ya a Gabriel Ayme, el conductor independiente cuya solicitud del 19/10/2026 llegó
 * dirigida a un «equipo» que no existe. Por id, no por nombre: el nombre llevaba una errata hasta
 * esa misma mañana.
 */
final class Version20261019120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'travel_organizacion: es_persona, y marcar a Gabriel Ayme';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE travel_organizacion ADD es_persona TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql("UPDATE travel_organizacion SET es_persona = 1 WHERE id = UNHEX('703444B67B0F11F19FA70624B3441857')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE travel_organizacion DROP es_persona');
    }
}
