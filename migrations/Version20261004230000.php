<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rellena `operacion_servicio.tarifa_procedencia` desde la cotización, que ya la tenía congelada.
 *
 * La columna nació vacía en `Version20261004200000` y el snapshot sólo la escribe cuando se
 * recalcula una fila. Pero **`operacion:resincronizar` no toca filas de una Orden de Servicio ya
 * emitida, ni con `--force`**, así que las filas que más interesan —las que ya tienen orden, como
 * la OS-20261004-445— no se rellenarían nunca por ese camino.
 *
 * ⚠️ **Y esa guarda no está protegiendo nada aquí, que es lo que hace legítimo el relleno.** Lo
 * que impide es sobrescribir un campo que un operador pudo haber editado a mano. Este campo
 * **acaba de existir**: nadie lo ha editado, no hay conflicto posible, y el valor que se copia ya
 * estaba congelado en `cotizacion_cottarifa.procedencia_snapshot` desde que se cotizó. No se está
 * decidiendo nada: se está acabando de mudar un dato que ya estaba decidido.
 *
 * Va por SQL y no por comando porque es exactamente el caso de la regla: una columna plana, sin
 * `#[AutoTranslate]` y sin ningún listener que recalcule al guardar. Ver CLAUDE.md, «Qué entra por
 * migración y qué tiene que entrar por comando».
 *
 * Medido antes de escribirla: 85 filas en La Biblia, de las que **10** tienen procedencia en la
 * cotización y 0 la tenían aquí. Las otras 75 se quedan nulas, que es su valor correcto —«sin
 * restricción»—, no un dato que falte.
 *
 * El `WHERE … IS NULL` no es decorativo: hace la migración repetible y, si alguien resincroniza
 * entre el despliegue y esto, no le pisa lo que el snapshot ya escribió.
 */
final class Version20261004230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rellena la procedencia de la tarifa en La Biblia desde la cotización';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            UPDATE operacion_servicio ops
            INNER JOIN cotizacion_cottarifa t ON t.id = ops.cotizacion_tarifa_id
            SET ops.tarifa_procedencia = t.procedencia_snapshot
            WHERE ops.tarifa_procedencia IS NULL
              AND t.procedencia_snapshot IS NOT NULL
              AND TRIM(t.procedencia_snapshot) <> \'\'
        ');
    }

    public function down(Schema $schema): void
    {
        // Se vuelve al estado anterior: la columna existía y estaba entera vacía (0 filas con
        // valor, medido). Vaciarla no pierde nada que no se pueda recomponer desde la cotización.
        $this->addSql('UPDATE operacion_servicio SET tarifa_procedencia = NULL');
    }
}
