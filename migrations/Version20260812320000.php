<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El enlace guarda sus HITOS derivados, no sólo las dos fechas agregadas.
 *
 * `milestones` es el mapa plano de siempre —`start`, `end`, `created`—, y `start`/`end` salen
 * del mínimo y el máximo de todos los tramos de la reserva. Eso aplasta una estancia partida:
 * quien se va tres días a Machu Picchu y vuelve a otra casita aparece como una sola ventana, y
 * ni el día que se va ni el día que vuelve existen para la mensajería.
 *
 * `hitos` es la lista completa —llegada, salidas temporales, reingresos, cambios de casita,
 * salida final—, con su fecha y su detalle. Es una LISTA y no un mapa porque un viaje largo
 * puede tener varias escapadas, y en `clave => fecha` sólo cabe una de cada.
 *
 * Nace vacía y **no la lee nadie todavía**: la rellena `PmsSincronizadorDeEnlace` en cada cambio
 * de la reserva, y quien la consuma será el motor de reglas cuando sepa programar una ocurrencia
 * por hito en vez de una por tipo. Ver docs/Mensajeria.md §20.7.
 */
final class Version20260812320000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'pms_conversacion_enlace.hitos: la lista completa de momentos de la estancia';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_conversacion_enlace ADD hitos JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_conversacion_enlace DROP hitos');
    }
}
