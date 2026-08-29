<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El itinerario en texto libre se retira: ahora vive en `cotizacion_vuelo`.
 *
 * `cotizacion_file_grupo.detalle` guardaba líneas como
 *
 *     * Retorno JA7013 · LIM 23/09 06:30 → CUZ 23/09 07:55
 *
 * Eso ya está en los vuelos, lo lee la ficha del expediente y lo lee el agente
 * (`ConsultarPadronSkill::itinerarioDe()`). Dejar las dos copias es garantizar que un día
 * discrepen —y la que se quedaría vieja es justo la que ve el operador.
 *
 * ⚠️ Sólo se vacían los grupos que **ya tienen vuelos cargados**. Uno aéreo sin vuelos conserva
 * su texto: es lo único que se sabe de él, y borrarlo sería perder el dato en vez de migrarlo.
 *
 * ⚠️ Y sólo los de tipo `reserva_aerea`. El campo es genérico y puede acabar describiendo una
 * habitación; ahí no se toca nada.
 *
 * Va por migración porque `detalle` es un `text` plano: ni `#[AutoTranslate]` ni listeners.
 *
 * Irreversible: el texto era la copia, no el original.
 */
final class Version20260829010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Vacía el itinerario en texto de los grupos aéreos que ya tienen vuelos cargados.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE cotizacion_file_grupo g
               SET g.detalle = NULL
             WHERE g.tipo = 'reserva_aerea'
               AND g.detalle IS NOT NULL
               AND EXISTS (SELECT 1 FROM cotizacion_grupo_vuelo gv WHERE gv.grupo_id = g.id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'El texto era una copia del itinerario, que ahora vive en cotizacion_vuelo.',
        );
    }
}
