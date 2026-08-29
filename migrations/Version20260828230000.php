<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `emitido` se muda del vuelo a la reserva, que es de quien era.
 *
 * ⚠️ Lo puse en `cotizacion_vuelo` y estaba mal: el `H2 5002` es un vuelo real y emitido para
 * quien tenga su billete; lo que está pagado sin emitir son los 88 billetes de Sky. Un mismo
 * vuelo puede llevar reservas emitidas y sin emitir a la vez, así que ahí la bandera no
 * significaba nada.
 *
 * Vive junto a la `clave`, que es donde está el PNR: mientras es `false`, esa clave es un
 * localizador provisional.
 *
 * Se aprovecha el paso para trasladar el dato: los grupos de los vuelos que estaban marcados
 * sin emitir heredan la marca. Hoy son los dos de Sky.
 */
final class Version20260828230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '`emitido` pasa de cotizacion_vuelo a cotizacion_file_grupo: es de la reserva.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_file_grupo ADD emitido TINYINT(1) DEFAULT 1 NOT NULL');

        // Traslado del dato antes de tirar la columna: lo que el vuelo decía, lo dicen ahora
        // sus reservas. Va con INNER JOIN por el puente, así que sólo toca las enlazadas.
        $this->addSql(<<<'SQL'
            UPDATE cotizacion_file_grupo g
            INNER JOIN cotizacion_grupo_vuelo gv ON gv.grupo_id = g.id
            INNER JOIN cotizacion_vuelo v ON v.id = gv.vuelo_id
               SET g.emitido = 0
             WHERE v.emitido = 0
        SQL);

        $this->addSql('ALTER TABLE cotizacion_vuelo DROP emitido');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_vuelo ADD emitido TINYINT(1) DEFAULT 1 NOT NULL');
        $this->addSql(<<<'SQL'
            UPDATE cotizacion_vuelo v
            INNER JOIN cotizacion_grupo_vuelo gv ON gv.vuelo_id = v.id
            INNER JOIN cotizacion_file_grupo g ON g.id = gv.grupo_id
               SET v.emitido = 0
             WHERE g.emitido = 0
        SQL);
        $this->addSql('ALTER TABLE cotizacion_file_grupo DROP emitido');
    }
}
