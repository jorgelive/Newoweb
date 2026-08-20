<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La Biblia separa lo que dijo la cotización de lo que decide operaciones.
 *
 * Hasta ahora había **una sola columna** para las dos cosas: el snapshot escribía
 * `prestador_nombre` desde la cotización y el operador la sobrescribía a mano en el cuadro de
 * tráfico. Por eso cada reconciliación tenía que **adivinar quién había sido** —comparando
 * contra `snapshot_origen`— y sacar un conflicto para que decidiera una persona.
 *
 * Con dos columnas no hay nada que adivinar, y el problema desaparece en vez de resolverse:
 *
 *     la cotización escribe SÓLO la cotizada   →  nunca pisa al operador
 *     el operador escribe SÓLO la real         →  nunca lo pisa la cotización
 *     lo que vale = real ?? cotizado
 *
 * Es la convención que la entidad ya usaba dos veces: `hora_componente` ↔ `hora_recojo_real`,
 * `costo_cotizado` ↔ `costo_real_operativo`.
 *
 * ## El rescate
 *
 * ⚠️ **Lo que el operador ya había editado vive hoy en la columna cotizada**, y si se deja ahí
 * lo pisará la próxima reconciliación. Se reconoce sin ambigüedad: `snapshot_origen` guarda la
 * foto de lo que puso la cotización, así que **valor actual ≠ foto ⟹ lo cambió una persona**.
 * Esas filas se mueven a la columna real.
 *
 * Se hace en SQL y no por el ORM porque no hay nada que recalcular —es mover texto entre dos
 * columnas de la misma fila— y `OperacionServicio` tiene listeners que no pintan nada aquí.
 */
final class Version20260820180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'OperacionServicio: prestador, su servicio y comprador ganan columna «real», y se rescata lo ya editado.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_servicio
            ADD prestador_servicio_maestro_id VARCHAR(36) DEFAULT NULL,
            ADD prestador_real_maestro_id VARCHAR(36) DEFAULT NULL,
            ADD prestador_real_nombre VARCHAR(150) DEFAULT NULL,
            ADD prestador_servicio_real_maestro_id VARCHAR(36) DEFAULT NULL,
            ADD prestador_servicio_real_nombre VARCHAR(255) DEFAULT NULL,
            ADD comprador_real_maestro_id VARCHAR(36) DEFAULT NULL,
            ADD comprador_real_nombre VARCHAR(150) DEFAULT NULL');

        // El rescate. `JSON_UNQUOTE(JSON_EXTRACT(...))` devuelve NULL si la clave no está —una
        // fila anterior a `snapshot_origen`—, y ahí NO se toca nada: sin foto de referencia no
        // se puede afirmar que lo cambiara una persona, y mover por si acaso inventaría datos.
        foreach (['prestador_nombre' => 'prestador_real_nombre', 'comprador_nombre' => 'comprador_real_nombre'] as $cotizada => $real) {
            $clave = str_replace(['prestador_nombre', 'comprador_nombre'], ['prestadorNombre', 'compradorNombre'], $cotizada);

            $this->addSql(sprintf(
                'UPDATE operacion_servicio
                    SET %2$s = %1$s
                  WHERE %1$s IS NOT NULL
                    AND %1$s <> \'\'
                    AND JSON_UNQUOTE(JSON_EXTRACT(snapshot_origen, \'$.%3$s\')) IS NOT NULL
                    AND JSON_UNQUOTE(JSON_EXTRACT(snapshot_origen, \'$.%3$s\')) <> %1$s',
                $cotizada,
                $real,
                $clave
            ));

            // Y la cotizada vuelve a decir lo que dijo la cotización. Sin esto se queda con la
            // edición del operador —duplicada en las dos columnas— y la próxima reconciliación
            // la marcaría como conflicto para «arreglar» algo que ya está en su sitio.
            $this->addSql(sprintf(
                'UPDATE operacion_servicio
                    SET %1$s = JSON_UNQUOTE(JSON_EXTRACT(snapshot_origen, \'$.%3$s\'))
                  WHERE %2$s IS NOT NULL
                    AND JSON_UNQUOTE(JSON_EXTRACT(snapshot_origen, \'$.%3$s\')) IS NOT NULL',
                $cotizada,
                $real,
                $clave
            ));
        }
    }

    public function down(Schema $schema): void
    {
        // Las ediciones vuelven a la columna cotizada, que es de donde salieron.
        $this->addSql('UPDATE operacion_servicio SET prestador_nombre = prestador_real_nombre WHERE prestador_real_nombre IS NOT NULL');
        $this->addSql('UPDATE operacion_servicio SET comprador_nombre = comprador_real_nombre WHERE comprador_real_nombre IS NOT NULL');

        $this->addSql('ALTER TABLE operacion_servicio
            DROP prestador_servicio_maestro_id,
            DROP prestador_real_maestro_id,
            DROP prestador_real_nombre,
            DROP prestador_servicio_real_maestro_id,
            DROP prestador_servicio_real_nombre,
            DROP comprador_real_maestro_id,
            DROP comprador_real_nombre');
    }
}
