<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La línea congelada recuerda si el proveedor ya había confirmado la hora.
 *
 * Distingue dos cosas que se parecen y no lo son:
 *
 *     estaba NULA y ahora hay hora   →  el proveedor CONFIRMÓ. Cambio menor: se aplica y se avisa.
 *     estaba puesta y ahora es otra  →  MODIFICACIÓN. Hay que reemitir.
 *
 * Cuando le pides un servicio a un proveedor, **la hora te la dice él al confirmar**: que
 * aparezca es el final normal del flujo, no un descuido. Sin esta columna, cada orden que sale
 * bien se marcaría sucia y habría que reemitirla — el aviso se volvería ruido y nadie lo miraría.
 *
 * Nace nula, incluidas las órdenes ya emitidas. Es lo correcto: de ellas no se sabe si la hora
 * venía confirmada, y suponer que sí convertiría una confirmación futura en una modificación
 * falsa. Lo peor que pasa es que la primera vez salga como cambio menor, que es inofensivo.
 */
final class Version20260820260000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ítem de orden: recuerda si la hora de recojo ya venía confirmada al emitir.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_orden_servicio_item ADD hora_recojo_confirmada VARCHAR(10) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_orden_servicio_item DROP hora_recojo_confirmada');
    }
}
