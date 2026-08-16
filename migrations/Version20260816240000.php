<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El catálogo maestro también sube el proveedor de la tarifa al componente.
 *
 * ── El mismo problema, un nivel más arriba ──────────────────────────────────
 * En la cotización ya se hizo (`Version20260816160000`). Aquí el síntoma era aún más claro:
 * `travel_tarifa.proveedor_id` estaba en **5 de 904** y `proveedor_servicio_id` en **0 de
 * 904**. No es desidia — un componente llega a tener 19 tarifas y nadie repite el mismo
 * proveedor 19 veces. El campo no se abandonó: nació impagable.
 *
 * Al subirlo al componente se llena una vez, y eso desbloquea de paso el filtro de tarifas
 * por prestador del editor de cotizaciones, que depende de este vínculo y hoy casi nunca se
 * activa por falta de dato (ver `docs/Cotizaciones.md` §6.c).
 *
 * ── El traslado es trivial ──────────────────────────────────────────────────
 * Medido antes de escribirlo: sólo **2 de 225** componentes tienen proveedor en alguna de
 * sus tarifas, y **cero** tienen dos proveedores distintos entre ellas. No hay nada que
 * desempatar.
 *
 * `nombre_para_proveedor` se queda en la tarifa a propósito: eso **sí** es por línea de
 * precio —cómo llama el proveedor a esa tarifa concreta— y es lo que la Orden de Servicio
 * usa ahora para hablarle en su idioma en vez de con nuestro código interno.
 */
final class Version20260816240000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TravelComponente: absorbe el proveedor que colgaba de cada TravelTarifa.';
    }

    /**
     * Va por `$this->connection` y no por `addSql()`: éste difiere la ejecución al final del
     * método, y aquí hace falta el orden real —crear columnas, copiar datos, soltar las
     * viejas— además de poder consultar el esquema para resolver los nombres de las FK.
     */
    public function up(Schema $schema): void
    {
        $this->connection->executeStatement('ALTER TABLE travel_componente
            ADD proveedor_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\',
            ADD proveedor_servicio_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\'');

        $this->connection->executeStatement('ALTER TABLE travel_componente
            ADD CONSTRAINT FK_componente_proveedor FOREIGN KEY (proveedor_id)
                REFERENCES travel_proveedor (id) ON DELETE SET NULL,
            ADD CONSTRAINT FK_componente_proveedor_servicio FOREIGN KEY (proveedor_servicio_id)
                REFERENCES travel_proveedor_servicio (id) ON DELETE SET NULL');

        // Nombres tal y como los genera Doctrine (hash de tabla+columna, deterministas):
        // con nombres propios `doctrine:schema:validate` marca deriva en cada despliegue.
        $this->connection->executeStatement('CREATE INDEX IDX_4329A17FCB305D73 ON travel_componente (proveedor_id)');
        $this->connection->executeStatement('CREATE INDEX IDX_4329A17FDB905830 ON travel_componente (proveedor_servicio_id)');

        // Sube el dato desde las tarifas. MIN() sobre un conjunto sin discrepancias —se
        // comprobó que ningún componente tiene dos proveedores distintos—; sólo sirve para
        // ignorar las tarifas que lo tienen a NULL.
        $this->connection->executeStatement('UPDATE travel_componente c
            JOIN (
                SELECT componente_id,
                       MIN(proveedor_id) AS prov,
                       MIN(proveedor_servicio_id) AS serv
                FROM travel_tarifa
                WHERE proveedor_id IS NOT NULL OR proveedor_servicio_id IS NOT NULL
                GROUP BY componente_id
            ) t ON t.componente_id = c.id
            SET c.proveedor_id = t.prov,
                c.proveedor_servicio_id = t.serv');

        // Las FK las nombró Doctrine (`FK_28AB0FE0…`), así que se resuelven consultando el
        // esquema en vez de escribirlas a mano: un nombre hardcodeado que no exista aborta
        // el ALTER entero, y aquí el nombre depende de un hash que no conviene asumir.
        foreach (['proveedor_id', 'proveedor_servicio_id'] as $columna) {
            $fk = $this->connection->fetchOne(
                "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'travel_tarifa'
                   AND COLUMN_NAME = :col AND REFERENCED_TABLE_NAME IS NOT NULL",
                ['col' => $columna]
            );

            if ($fk !== false) {
                $this->connection->executeStatement(
                    sprintf('ALTER TABLE travel_tarifa DROP FOREIGN KEY %s', $fk)
                );
            }
        }

        $this->connection->executeStatement(
            'ALTER TABLE travel_tarifa DROP proveedor_id, DROP proveedor_servicio_id'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE travel_tarifa
            ADD proveedor_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\',
            ADD proveedor_servicio_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\'');
        $this->addSql('UPDATE travel_tarifa t
            JOIN travel_componente c ON c.id = t.componente_id
            SET t.proveedor_id = c.proveedor_id, t.proveedor_servicio_id = c.proveedor_servicio_id');

        $this->addSql('ALTER TABLE travel_componente
            DROP FOREIGN KEY FK_componente_proveedor,
            DROP FOREIGN KEY FK_componente_proveedor_servicio');
        $this->addSql('ALTER TABLE travel_componente
            DROP proveedor_id, DROP proveedor_servicio_id');
    }
}
