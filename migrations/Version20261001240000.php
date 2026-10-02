<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Guardar el equipaje se ofrece siempre que ayude; lo de «gratis», sólo si lo preguntan.
 *
 * El párrafo decía a la vez «ofrécelo con confianza» y «no lo ofrezcas de entrada», y el agente
 * se quedaba con lo segundo: el aviso de salida ofrece guardar el equipaje y el agente callaba lo
 * mismo en la conversación. Jorge, 01/10/2026: la intención es ofrecerlo siempre que le sirva al
 * huésped —llega antes de la entrada, sale antes de irse de la ciudad, se va unos días—; lo único
 * que se reserva para cuando pregunta es el precio, y la respuesta es GRATIS.
 *
 * Sólo cambia el primer párrafo de `agente_contenido` de «Equipaje y horarios flexibles (general)»,
 * de «EQUIPAJE. Tenemos almacen» a «no antes.». Va por migración porque `agente_contenido` no se
 * traduce (ver CLAUDE.md, «Qué entra por migración»). Si el párrafo ya no está como se escribió,
 * no se toca.
 */
final class Version20261001240000 extends AbstractMigration
{
    private const string ITEM = 'Equipaje y horarios flexibles (general)';
    private const string DESDE = 'EQUIPAJE. Tenemos almacen';
    private const string HASTA = 'se cuenta cuando lo preguntan, no antes.';

    private const string PARRAFO = "EQUIPAJE. Tenemos almacén: puede dejar las maletas si llega antes de la entrada o si ya\n"
        . "hizo el check-out y sigue en la ciudad. Ofrécelo siempre que le ayude: llega antes de la entrada,\n"
        . "sale antes de irse de la ciudad o se va unos días. Es GRATIS para nuestros huéspedes: si pregunta\n"
        . 'el costo, díselo sin rodeos y sin avisar a nadie.';

    public function getDescription(): string
    {
        return 'Equipaje: se ofrece siempre que ayude; que es gratis, sólo si lo preguntan.';
    }

    public function up(Schema $schema): void
    {
        $actual = $this->connection->fetchOne(
            'SELECT agente_contenido FROM pms_guia_item WHERE nombre_interno = ?',
            [self::ITEM]
        );

        if (
            !is_string($actual)
            || ($inicio = strpos($actual, self::DESDE)) === false
            || ($fin = strpos($actual, self::HASTA, $inicio)) === false
        ) {
            $this->write('El párrafo del equipaje ya no está como se escribió: no se toca.');

            return;
        }

        $this->addSql(
            'UPDATE pms_guia_item SET agente_contenido = ? WHERE nombre_interno = ?',
            [substr($actual, 0, $inicio) . self::PARRAFO . substr($actual, $fin + strlen(self::HASTA)), self::ITEM]
        );
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('El texto anterior no se guarda: está en git (Version20261001240000).');
    }
}
