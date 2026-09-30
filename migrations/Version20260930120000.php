<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Devuelve a `extension` las noches de horario extra que el pull había pasado a `bloqueo`.
 *
 * `bloqueo` y `extension` comparten código en Beds24 (`black`), y el pull, al leerlo de vuelta,
 * se quedaba con el primero que devolvía la base. Una extensión se reconoce sin ambigüedad por
 * `evento_origen_id`: un bloqueo a mano no cuelga de ninguna estancia. Son dos filas (M4E23R y
 * UV5XPW el 30/09/2026).
 *
 * Desde este despliegue el push escribe nuestro estado en `custom3` y el pull lo lee; estas dos
 * se empujaron antes y no lo llevan, así que las protege la segunda regla de
 * `BookingPullPersister::elegirEstado()`: sin `custom3`, se conserva el estado que ya tienen.
 */
final class Version20260930120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Las noches de horario extra que el pull dejó en bloqueo vuelven a extension.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE pms_evento_calendario SET estado_id = 'extension' WHERE evento_origen_id IS NOT NULL AND estado_id = 'bloqueo'");
    }

    public function down(Schema $schema): void
    {
        // Sin vuelta atrás: no se sabe cuáles eran, y dejarlas en `extension` es lo correcto.
    }
}
