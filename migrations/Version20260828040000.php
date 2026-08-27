<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rellena el NOMBRE del servicio del prestador donde sólo estaba su id.
 *
 * La habitación estaba asignada —`prestador_servicio_maestro_id` puesto— pero
 * `prestador_servicio_nombre_snapshot` quedó vacío. La app del huésped no lo notaba porque
 * resuelve el servicio **en vivo** contra el catálogo; la Orden lee el **snapshot**, así que se
 * quedaba muda: el proveedor recibía «Hotel 4 estrellas por grupo» en vez de «Habitación premium».
 *
 * Es la misma forma de fallo de todo este día: **no falla nada, sólo deja de aparecer algo**, y un
 * campo vacío es indistinguible de «este componente no tiene habitación».
 *
 * El editor sí lo escribe al elegir la habitación en el desplegable; estos componentes recibieron
 * el id por otra vía.
 *
 * ── Tres saltos, en orden ───────────────────────────────────────────────────
 * cotización → La Biblia → líneas ya congeladas de la Orden. Cada uno bebe del anterior, así que
 * el orden importa y por eso van con `executeStatement`, que ejecuta ya, y no con `addSql`.
 *
 * ⚠️ Sólo rellena lo VACÍO. Un nombre distinto del maestro es una decisión —el operador pudo
 * escribirlo a mano— y no se pisa. Y en las líneas congeladas, además, sería reescribir un
 * documento emitido.
 */
final class Version20260828040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rellena prestadorServicioNombre en cotización, Biblia y órdenes donde sólo había el id.';
    }

    public function up(Schema $schema): void
    {
        // 1. La cotización, desde el catálogo. El id ya apunta bien; falta el texto.
        $cot = $this->connection->executeStatement(
            'UPDATE cotizacion_cotcomponente k
               JOIN travel_organizacion_servicio s
                 ON HEX(s.id) = UPPER(REPLACE(k.prestador_servicio_maestro_id, "-", ""))
                SET k.prestador_servicio_nombre_snapshot = s.nombre
              WHERE (k.prestador_servicio_nombre_snapshot IS NULL OR k.prestador_servicio_nombre_snapshot = "")
                AND s.nombre <> ""'
        );

        // 2. La Biblia, desde la cotización que acabamos de completar.
        $biblia = $this->connection->executeStatement(
            'UPDATE operacion_servicio o
               JOIN cotizacion_cotcomponente k ON k.id = o.cotizacion_componente_id
                SET o.prestador_servicio_nombre = k.prestador_servicio_nombre_snapshot
              WHERE (o.prestador_servicio_nombre IS NULL OR o.prestador_servicio_nombre = "")
                AND k.prestador_servicio_nombre_snapshot IS NOT NULL
                AND k.prestador_servicio_nombre_snapshot <> ""'
        );

        // 3. Las líneas ya congeladas, desde su fila de La Biblia. `operacion_servicio_id` es
        //    texto con guiones y el id es binario: hay que convertir.
        $lineas = $this->connection->executeStatement(
            'UPDATE operacion_orden_servicio_item i
               JOIN operacion_servicio s ON s.id = UNHEX(REPLACE(i.operacion_servicio_id, "-", ""))
                SET i.prestador_servicio_nombre = s.prestador_servicio_nombre
              WHERE (i.prestador_servicio_nombre IS NULL OR i.prestador_servicio_nombre = "")
                AND s.prestador_servicio_nombre IS NOT NULL
                AND s.prestador_servicio_nombre <> ""'
        );

        $this->write(sprintf(
            '    <info>cotización: %d · La Biblia: %d · líneas de orden: %d</info>',
            $cot,
            $biblia,
            $lineas
        ));
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Corrección de datos: no se guardó cuáles estaban vacíos por decisión y cuáles por descuido.'
        );
    }
}
