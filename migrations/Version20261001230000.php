<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Entrada temprana y salida tardía: el costo es POLÍTICA, y el agente sólo lo cuenta si preguntan.
 *
 * Jorge, 01/10/2026: lo publicado se queda como está —«sujeto a disponibilidad y tiene un costo
 * adicional»—, porque es la política. Pero lo decide el equipo, que suele flexibilizarlo, y por eso
 * el agente no lo confirma: apunta la petición (`confirmar_hora`) y el equipo decide. Del costo,
 * nada de entrada; si el huésped pregunta, sí, tiene costo —«el que pregunta está dispuesto a
 * pagar»— y el detalle lo confirma el equipo.
 *
 * Sólo cambia el último párrafo de `agente_contenido` de «Equipaje y horarios flexibles (general)»,
 * el que empieza por «INGRESO TEMPRANO Y SALIDA TARDE». Va por migración porque `agente_contenido`
 * no se traduce (ver CLAUDE.md, «Qué entra por migración»). Si el párrafo ya no está —lo editó
 * alguien a mano—, no se toca.
 */
final class Version20261001230000 extends AbstractMigration
{
    private const string ITEM = 'Equipaje y horarios flexibles (general)';
    private const string MARCA = 'INGRESO TEMPRANO Y SALIDA TARDE.';

    private const string PARRAFO = 'INGRESO TEMPRANO Y SALIDA TARDE. Es política: sujeto a disponibilidad, con un día de '
        . "anticipación y con un costo adicional. Lo decide el equipo, que suele flexibilizarlo, así que\n"
        . "no lo confirmes tú: apunta lo que pide con confirmar_hora —deja la petición y avisa al equipo—\n"
        . "y dile que lo consultas.\n\n"
        . "EL COSTO, SÓLO SI LO PREGUNTA. Si pregunta si tiene costo, la respuesta es sí: hay un costo\n"
        . "adicional y el equipo le confirma el detalle. Mientras no lo pregunte, la conversación es de\n"
        . "horas y disponibilidad.\n\n"
        . 'Las horas normales están en el tema «Horario de ingreso y salida».';

    public function getDescription(): string
    {
        return 'Equipaje y horarios flexibles: el costo del horario extra, como política y sólo si lo preguntan.';
    }

    public function up(Schema $schema): void
    {
        $actual = $this->connection->fetchOne(
            'SELECT agente_contenido FROM pms_guia_item WHERE nombre_interno = ?',
            [self::ITEM]
        );

        if (!is_string($actual) || ($inicio = strpos($actual, self::MARCA)) === false) {
            $this->write('El párrafo ya no está como se escribió: no se toca.');

            return;
        }

        $this->addSql(
            'UPDATE pms_guia_item SET agente_contenido = ? WHERE nombre_interno = ?',
            [substr($actual, 0, $inicio) . self::PARRAFO, self::ITEM]
        );
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('El texto anterior no se guarda: está en git (Version20261001230000).');
    }
}
