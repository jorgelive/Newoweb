<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Flag horaServicioCompleto: promueve la hora de un componente al horario global
 * de toda la excursión (servicio/itinerario) en vez del segmento donde se ancla.
 * Se agrega tanto en la plantilla Travel como en el snapshot de la cotización.
 */
final class Version20260727234500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agrega hora_servicio_completo a travel_segmento_componente y cotizacion_cotcomponente.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE travel_segmento_componente ADD hora_servicio_completo TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE cotizacion_cotcomponente ADD hora_servicio_completo TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE travel_segmento_componente DROP hora_servicio_completo');
        $this->addSql('ALTER TABLE cotizacion_cotcomponente DROP hora_servicio_completo');
    }
}
