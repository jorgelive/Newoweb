<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El adelanto de una cotización pasa a valer 50 % por defecto, como en Oweb.
 *
 * La columna `adelanto` vino de Oweb, donde era el PORCENTAJE que se pedía para reservar (50 por
 * defecto). Aquí nació con 0 y nadie la usaba: valía 0,00 en las 23 propuestas, ninguna puesta a
 * mano. Desde hoy la lee el bloque «Cómo reservar» de pax (ver `ComoReservarResolver`), y con 0
 * diría «reserva sin adelanto» en todas.
 *
 * Sólo se tocan las que valen 0: un valor distinto lo habría escrito alguien a propósito.
 */
final class Version20261016150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cotización: adelanto 50 % por defecto (y en las que valían 0)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE cotizacion_cotizacion CHANGE adelanto adelanto NUMERIC(12, 2) DEFAULT '50.00' NOT NULL");
        $this->addSql('UPDATE cotizacion_cotizacion SET adelanto = 50 WHERE adelanto = 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE cotizacion_cotizacion CHANGE adelanto adelanto NUMERIC(12, 2) DEFAULT '0.00' NOT NULL");
    }
}
