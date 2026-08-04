<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Salida tardía en la estancia (`PmsEventoCalendario::$salidaTardia`).
 *
 * Sustituye a la práctica de crear una SEGUNDA estancia de una noche para
 * reservar el late check-out: aquella inflaba noches y ADR, partía la reserva en
 * dos y obligaba a inventar un check-in que nunca ocurría.
 *
 * Con la casilla marcada, el push manda a Beds24 un `departure` un día después
 * —la noche no se puede vender— y se genera un cargo de SERVICIO con el precio
 * de esa noche. Ver docs/PmsBeds24ReservasSync.md.
 *
 * Las estancias existentes quedan en `false`: las duplicadas que ya se crearon a
 * mano siguen como están, esto no las convierte.
 */
final class Version20260804230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade salida_tardia a pms_evento_calendario.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_evento_calendario ADD salida_tardia TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_evento_calendario DROP salida_tardia');
    }
}
