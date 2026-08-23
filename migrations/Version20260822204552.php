<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El booleano «puntos siempre visibles» se parte en dos enums, uno por lado.
 *
 * El control anterior sólo podía AÑADIR líneas. Los enums añaden `OCULTO` —quitar el extremo de
 * una cadena— y separan el recojo de la entrega, que es la granularidad que ya tiene la regla:
 * el primero de una cadena enseña su recojo y el último su entrega.
 *
 * ⚠️ Va por SQL y no por comando: es esquema y un `UPDATE` de conversión que no pasa por ningún
 * listener. Lo que estuviera en `true` pasa a `SIEMPRE` en los dos lados, que es exactamente lo
 * que hacía.
 *
 * Escrita a mano, como las anteriores del módulo: el `diff` arrastra el borrado de las tablas
 * `energia_*` y un backup sin entidad, y eso se retira en su propia migración.
 */
final class Version20260822204552 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Visibilidad de puntos: de un booleano a dos enums (recojo y entrega).';
    }

    public function up(Schema $schema): void
    {
        foreach (['operacion_servicio', 'operacion_orden_servicio_item'] as $tabla) {
            $this->addSql("ALTER TABLE $tabla ADD visibilidad_recojo VARCHAR(12) DEFAULT 'auto' NOT NULL, ADD visibilidad_entrega VARCHAR(12) DEFAULT 'auto' NOT NULL");
            $this->addSql("UPDATE $tabla SET visibilidad_recojo = 'siempre', visibilidad_entrega = 'siempre' WHERE puntos_siempre_visibles = 1");
            $this->addSql("ALTER TABLE $tabla DROP puntos_siempre_visibles");
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['operacion_servicio', 'operacion_orden_servicio_item'] as $tabla) {
            $this->addSql("ALTER TABLE $tabla ADD puntos_siempre_visibles TINYINT(1) DEFAULT 0 NOT NULL");
            $this->addSql("UPDATE $tabla SET puntos_siempre_visibles = 1 WHERE visibilidad_recojo = 'siempre' OR visibilidad_entrega = 'siempre'");
            $this->addSql("ALTER TABLE $tabla DROP visibilidad_recojo, DROP visibilidad_entrega");
        }
    }
}
