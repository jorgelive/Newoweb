<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La Orden de Servicio pasa a tener contenido PROPIO.
 *
 * Hasta ahora sus ítems eran las filas vivas de La Biblia enlazadas por `orden_servicio_id`, y
 * eso dejaba una contradicción sin salida:
 *
 *     liberas las filas  →  la Orden anulada queda VACÍA
 *     las dejas atadas   →  no se pueden volver a pedir en otra Orden
 *
 * `operacion_orden_servicio_item` congela lo que el documento pidió. **Anular = soltar el
 * vínculo vivo y conservar el congelado**: la Orden sigue diciendo lo que pidió y la fila queda
 * libre para la siguiente.
 *
 * `reemplaza_a_id` encadena las reemisiones: «OS-014 anulada → OS-021» es la pregunta que se
 * hace de verdad cuando un proveedor reclama.
 *
 * ⚠️ **Las órdenes que ya existen se quedan sin líneas congeladas, y es correcto.** Se emitieron
 * cuando el contenido eran las filas vivas; inventarles un snapshot con los datos de hoy sería
 * escribir que pidieron algo que quizá no pidieron. Sus divergencias no se calculan —sin líneas
 * no hay contra qué comparar— y al anularlas se comportan como antes.
 */
final class Version20260820240000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Orden de Servicio: líneas congeladas propias y cadena de reemisión.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE operacion_orden_servicio_item (
            id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\',
            orden_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\',
            moneda_id VARCHAR(3) DEFAULT NULL,
            operacion_servicio_id VARCHAR(36) DEFAULT NULL,
            descripcion VARCHAR(255) NOT NULL,
            fecha_servicio DATE DEFAULT NULL,
            hora VARCHAR(10) DEFAULT NULL,
            cantidad_pax INT DEFAULT NULL,
            cantidad NUMERIC(10, 2) DEFAULT NULL,
            importe NUMERIC(12, 2) NOT NULL,
            prestador_nombre VARCHAR(150) DEFAULT NULL,
            prestador_servicio_nombre VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_39AFD4DF9750851F (orden_id),
            INDEX IDX_39AFD4DFB77634D2 (moneda_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE operacion_orden_servicio_item
            ADD CONSTRAINT FK_39AFD4DF9750851F FOREIGN KEY (orden_id)
                REFERENCES operacion_orden_servicio (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE operacion_orden_servicio_item
            ADD CONSTRAINT FK_39AFD4DFB77634D2 FOREIGN KEY (moneda_id) REFERENCES maestro_moneda (id)');

        $this->addSql('ALTER TABLE operacion_orden_servicio ADD reemplaza_a_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\'');
        $this->addSql('CREATE INDEX IDX_76415CAF99E1778C ON operacion_orden_servicio (reemplaza_a_id)');
        $this->addSql('ALTER TABLE operacion_orden_servicio
            ADD CONSTRAINT FK_76415CAF99E1778C FOREIGN KEY (reemplaza_a_id)
                REFERENCES operacion_orden_servicio (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_orden_servicio DROP FOREIGN KEY FK_76415CAF99E1778C');
        $this->addSql('DROP INDEX IDX_76415CAF99E1778C ON operacion_orden_servicio');
        $this->addSql('ALTER TABLE operacion_orden_servicio DROP reemplaza_a_id');
        $this->addSql('DROP TABLE operacion_orden_servicio_item');
    }
}
