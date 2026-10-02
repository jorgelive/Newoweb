<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Equipaje: el precio, sólo como respuesta a la pregunta.
 *
 * Version20261001240000 dejó «Es GRATIS para nuestros huéspedes: si pregunta el costo, díselo…»
 * pegado a la oferta, y el agente lo soltaba sin que preguntaran: Franco (W2YRVK, 02/10/2026) pulsó
 * «Necesito más tiempo» y oyó dos veces «espacio gratuito». Era un dato suelto delante de la
 * condición, no una condición.
 *
 * Ahora las dos ramas van en positivo (CLAUDE.md, «Las condiciones se escriben en positivo»): al
 * ofrecerlo, de qué se habla —cuándo y cuántas piezas—; y en su propio bloque, la respuesta a la
 * pregunta del costo. Jorge, 02/10/2026: «dilo si pregunta» es una condición, no una negación. Si
 * vuelve a soltarlo, el siguiente paso es partir el ítem, como el depósito de garantía.
 *
 * Va por migración porque `agente_contenido` no se traduce. Si el párrafo no está como se escribió,
 * no se toca.
 */
final class Version20261002214940 extends AbstractMigration
{
    private const string ITEM = 'Equipaje y horarios flexibles (general)';
    private const string DESDE = 'EQUIPAJE. Tenemos almacén';
    private const string HASTA = 'díselo sin rodeos y sin avisar a nadie.';

    private const string PARRAFO = "EQUIPAJE. Tenemos almacén: puede dejar las maletas si llega antes de la entrada o si ya\n"
        . "hizo el check-out y sigue en la ciudad. Ofrécelo siempre que le ayude: llega antes de la entrada,\n"
        . "sale antes de irse de la ciudad o se va unos días. Al ofrecerlo, la conversación es de cuándo y\n"
        . "cuántas piezas.\n\n"
        . 'SI PREGUNTA EL COSTO: es gratis para nuestros huéspedes. Díselo sin rodeos y sin avisar a nadie.';

    public function getDescription(): string
    {
        return 'Equipaje: el precio, sólo como respuesta a la pregunta del costo.';
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
        $this->throwIrreversibleMigrationException('El texto anterior no se guarda: está en git (Version20261002214940).');
    }
}
