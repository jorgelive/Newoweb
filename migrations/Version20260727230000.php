<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Añade el flag totalesOcultos (modo catálogo unitario) a la cotización.
 */
final class Version20260727230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cotizacion.totalesOcultos: oculta referencias a cantidad de pasajeros / totales de grupo en la guía del cliente (modo catálogo unitario).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_cotizacion ADD totales_ocultos TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_cotizacion DROP totales_ocultos');
    }
}
