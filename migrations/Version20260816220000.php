<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Operación deja de hablar de «proveedor» donde siempre quiso decir COMPRADOR.
 *
 * ── Era la misma idea con el nombre equivocado ──────────────────────────────
 * `operacion_servicio.proveedor_nombre_manual` se documentaba como «a quién le mando la
 * solicitud y le pago» — que es literalmente la definición del comprador, no la del
 * proveedor. El nombre hacía creer que la orden va a quien pone el precio, y eso falla en
 * cuanto la tarifa es de un consorcio al que nadie escribe: le encargas a Futurismo que
 * compre las entradas, y la orden tiene que salir a nombre de Futurismo.
 *
 * Que fuera «manual» es la prueba: existía un campo a mano porque el dato automático —el
 * proveedor de la tarifa— respondía a otra pregunta. Ahora lo llena la cascada del
 * comprador, que cae al proveedor cuando nadie encargó nada.
 *
 * ── Qué se conserva ─────────────────────────────────────────────────────────
 * El valor **no se pierde**: se copia a `comprador_*` antes de soltar las columnas. Los 8
 * servicios que lo tenían escrito a mano conservan exactamente lo que había, porque lo que
 * había ya era el comprador.
 *
 * `operacion_orden_servicio` no tiene ninguna fila todavía, así que ahí el renombrado no
 * arrastra nada.
 */
final class Version20260816220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Operación: proveedor* → comprador* en servicio y orden de servicio.';
    }

    public function up(Schema $schema): void
    {
        // El dato viejo YA era el comprador: se conserva, no se descarta.
        $this->addSql('UPDATE operacion_servicio
            SET comprador_maestro_id = proveedor_maestro_id,
                comprador_nombre     = proveedor_nombre_manual
            WHERE proveedor_maestro_id IS NOT NULL
               OR TRIM(COALESCE(proveedor_nombre_manual, "")) <> ""');

        $this->addSql('ALTER TABLE operacion_servicio
            DROP proveedor_maestro_id,
            DROP proveedor_nombre_manual');

        $this->addSql('ALTER TABLE operacion_orden_servicio
            ADD comprador_maestro_id VARCHAR(36) DEFAULT NULL,
            ADD comprador_nombre VARCHAR(150) DEFAULT NULL');

        $this->addSql('UPDATE operacion_orden_servicio
            SET comprador_maestro_id = proveedor_maestro_id,
                comprador_nombre     = proveedor_nombre_manual');

        $this->addSql('ALTER TABLE operacion_orden_servicio
            DROP proveedor_maestro_id,
            DROP proveedor_nombre_manual');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_servicio
            ADD proveedor_maestro_id VARCHAR(36) DEFAULT NULL,
            ADD proveedor_nombre_manual VARCHAR(150) DEFAULT NULL');
        $this->addSql('UPDATE operacion_servicio
            SET proveedor_maestro_id = comprador_maestro_id,
                proveedor_nombre_manual = comprador_nombre');

        $this->addSql('ALTER TABLE operacion_orden_servicio
            ADD proveedor_maestro_id VARCHAR(36) DEFAULT NULL,
            ADD proveedor_nombre_manual VARCHAR(150) DEFAULT NULL');
        $this->addSql('UPDATE operacion_orden_servicio
            SET proveedor_maestro_id = comprador_maestro_id,
                proveedor_nombre_manual = comprador_nombre');
        $this->addSql('ALTER TABLE operacion_orden_servicio
            DROP comprador_maestro_id,
            DROP comprador_nombre');
    }
}
