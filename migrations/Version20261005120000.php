<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fase 2 de `docs/PlanModalidadDeTarifa.md`: la columna del cálculo, conviviendo con el booleano.
 *
 * `calculo` / `calculo_snapshot` nacen **nulables y sin mandar**: durante esta fase la verdad
 * sigue siendo `costo_por_grupo` / `es_grupal` más `rol`, y la columna nueva es una copia derivada
 * que las entidades mantienen al día desde sus setters. Existe para poder migrar a los
 * consumidores de uno en uno en la fase 3, sin que ninguno vea un dato a medias.
 *
 * ## La traducción, y por qué es lossless
 *
 * ```
 * rol = 'operativo'        → operativa     (gana sobre costo_por_grupo)
 * costo_por_grupo = 1      → grupal
 * el resto                 → individual
 * ```
 *
 * ⚠️ **`operativo` gana a propósito.** En el modelo nuevo una operativa multiplica por cantidad, y
 * las 22 operativas del catálogo son grupales con `cantidad = 1`: leerlas como «× cantidad» da
 * exactamente el mismo número. Medido antes de escribir esto — ver §3 del plan.
 *
 * Y en los snapshots no hay ni una operativa (0 de 300), así que **ningún documento vendido cambia
 * de importe**: todas las filas de cotización caen en `grupal` o `individual`, que es lo que ya
 * decía su booleano.
 *
 * ## Repetible
 *
 * El `WHERE … IS NULL` deja la migración repetible y no pisa lo que los setters hayan escrito
 * entretanto. Mismo criterio que `Version20261004230000`.
 */
final class Version20261005120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cálculo de tarifa (individual/grupal/operativa), derivado y sin mandar todavía';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE travel_tarifa ADD calculo VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE cotizacion_cottarifa ADD calculo_snapshot VARCHAR(20) DEFAULT NULL');

        $this->addSql("
            UPDATE travel_tarifa SET calculo = CASE
                WHEN rol = 'operativo' THEN 'operativa'
                WHEN costo_por_grupo = 1 THEN 'grupal'
                ELSE 'individual'
            END
            WHERE calculo IS NULL
        ");

        $this->addSql("
            UPDATE cotizacion_cottarifa SET calculo_snapshot = CASE
                WHEN rol_snapshot = 'operativo' THEN 'operativa'
                WHEN es_grupal = 1 THEN 'grupal'
                ELSE 'individual'
            END
            WHERE calculo_snapshot IS NULL
        ");
    }

    public function down(Schema $schema): void
    {
        // Se puede volver atrás sin perder nada: durante la fase 2 la columna es derivada, así que
        // tirarla sólo descarta una copia que los setters saben rehacer.
        $this->addSql('ALTER TABLE cotizacion_cottarifa DROP calculo_snapshot');
        $this->addSql('ALTER TABLE travel_tarifa DROP calculo');
    }
}
