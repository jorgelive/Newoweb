<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Quita la hora a las relaciones de ALOJAMIENTO.
 *
 * En la guía del pasajero, `esEstadia` se deduce de NO tener horas y terminar en fecha
 * posterior. Un alojamiento con hora deja de repetirse cada noche —se pinta sólo el primer
 * día— y además se coloca a esa hora en mitad de la tarde en vez de cerrar el día. Sin error.
 *
 * Había uno: `ALO-MACHU`, con 15:00, el único de 19 relaciones de alojamiento. No llegó a
 * morder porque las cotizaciones que lo usan traen `sin_horario = 1`, y `compConHora()` mira
 * ese flag antes que la hora; pero era una trampa armada para el siguiente que lo copiara.
 *
 * Va por migración y no por comando porque `hora` es una columna suelta: no la recalcula
 * ningún listener ni lleva `#[AutoTranslate]`.
 *
 * Desde ahora lo impide `TravelSegmentoComponente::validarAlojamientoSinHora()`.
 *
 * ⚠️ `down()` no restaura la hora: era un dato erróneo y no hay nada que devolver.
 */
final class Version20260828170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Un alojamiento no lleva hora: rompe la estadía en la guía del huésped.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE travel_segmento_componente sc
            INNER JOIN travel_componente c ON c.id = sc.componente_id
            SET sc.hora = NULL
            WHERE c.tipo = 'alojamiento' AND sc.hora IS NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'No se restaura: la hora en un alojamiento era el error, no el dato.',
        );
    }
}
