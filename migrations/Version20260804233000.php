<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Entrada temprana en la estancia (`PmsEventoCalendario::$entradaTemprana`).
 *
 * Espejo de `salida_tardia` (Version20260804230000) hacia atrás: con la casilla
 * marcada el push manda a Beds24 un `arrival` un día ANTES —la víspera deja de
 * ser vendible— y se genera un cargo de SERVICIO en 0.00 que valora el operador.
 * Ver docs/PmsBeds24ReservasSync.md §7.1.b.
 */
final class Version20260804233000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade entrada_temprana a pms_evento_calendario.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_evento_calendario ADD entrada_temprana TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_evento_calendario DROP entrada_temprana');
    }
}
