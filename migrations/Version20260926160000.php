<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fuera la columna `type` de `pms_bookings_pull_queue`: nadie la leía.
 *
 * La entidad la escribía siempre con el mismo valor por defecto —`beds24_bookings_arrival_range`,
 * las 15 028 filas de producción el 26/09/2026— y ningún código, consulta ni pantalla la leía.
 * PHPStan lo decía (`property.onlyWritten`) y la baseline lo tapaba como si fuera un candado
 * leído en SQL, que es el caso de `locked_at`. Ver `docs/TiposDeFrontera.md` §4.
 *
 * El `down()` la devuelve con su valor por defecto, que es lo único que llegó a guardar.
 */
final class Version20260926160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quita pms_bookings_pull_queue.type, que siempre valía lo mismo y nadie leía.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_bookings_pull_queue DROP COLUMN type');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE pms_bookings_pull_queue ADD type VARCHAR(50) NOT NULL DEFAULT 'beds24_bookings_arrival_range'");
    }
}
