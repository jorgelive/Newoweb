<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La TARIFA en la orden de servicio: cuál se compró y de quién es el precio.
 *
 * `operacion_orden_servicio_item` no tenía nada de la tarifa. Su única ranura parecida es
 * `descripcion`, que sale de una cascada de cinco prioridades en la que el nombre interno de la
 * tarifa va CUARTO, detrás del nombre interno del componente — relleno en 281 de 281 filas porque
 * el editor lo copia del maestro. Esa prioridad no podía ganar nunca, así que ninguna orden había
 * mostrado jamás el nombre de una tarifa.
 *
 * `tarifa_procedencia` es el dato que de verdad faltaba, y no se deduce del nombre: en un
 * `ticket_variable` la procedencia ES el precio (Vinicunca son PEN 20 el nacional y PEN 30 el
 * extranjero), y la orden pedía «una entrada» sin decir cuál.
 *
 * Las tres columnas van NULABLES y sin relleno: nulo significa «sin restricción» en la
 * procedencia y «la tarifa no tiene nombre interno» en la otra, que son valores legítimos y el
 * caso de la mayoría. Las órdenes ya emitidas se quedan como están —un documento emitido dice lo
 * que decía— y sólo `app:operacion:refrescar-ordenes-emitidas` las toca, bajo su propia condición.
 */
final class Version20261004200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tarifa en la orden de servicio: nombre interno y procedencia';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_servicio ADD tarifa_procedencia VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE operacion_orden_servicio_item ADD tarifa_nombre VARCHAR(255) DEFAULT NULL, ADD tarifa_procedencia VARCHAR(30) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_orden_servicio_item DROP tarifa_nombre, DROP tarifa_procedencia');
        $this->addSql('ALTER TABLE operacion_servicio DROP tarifa_procedencia');
    }
}
