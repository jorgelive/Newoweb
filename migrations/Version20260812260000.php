<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Limpia los cargos que quedaron huérfanos al borrar su estancia.
 *
 * ### Qué pasó
 *
 * El FK `evento_id` de `pms_cargo_financiero` es `ON DELETE SET NULL`. Cuando se retira una
 * ampliación de estadía, su estancia se borra y los cargos del tarifario que
 * `PmsCargosAutomaticosService` le había generado **sobreviven sin dueño**, sumando al total
 * para siempre.
 *
 * En producción son dos, de una reserva de Airbnb que llega el 2026-09-11: «Alojamiento»
 * 130.00 y «Suplemento de limpieza» 15.00, creados el 2026-08-03 22:42. La estancia real ya
 * tenía su cargo de Beds24 (163.42), así que el alojamiento estaba cobrado DOS veces y la
 * cabecera decía 308.42.
 *
 * El saldo salía a cero y por eso nadie lo vio en cuatro meses: `PmsPagoOtaAutomaticoService`
 * persigue siempre a `getTotalCargos()`, de modo que el depósito espejo del canal se actualizó
 * solo hasta 308.42 y los dos errores se compensaron.
 *
 * ### Qué hace
 *
 * Sólo borra lo que cumple las TRES condiciones a la vez: sin estancia (`evento_id IS NULL`),
 * de origen local (`beds24_item_id IS NULL`) y por tanto manual. Los cargos de Beds24 no se
 * tocan —`assertCargoBorrable()` tampoco los deja borrar a mano— y los cargos locales CON
 * estancia viva se quedan: son los ajustes que teclea el operador, como el «Descuento tipo de
 * cambio» de −0.20 de una reserva de Booking, que sí debe verse.
 *
 * Después cuadra la cabecera y el depósito espejo. Va en SQL y no por Doctrine porque una
 * migración no dispara listeners: si sólo se borraran los cargos, `total_cargos` se quedaría
 * en 308.42 y el saldo pasaría a mentir en el otro sentido.
 *
 * La resta es exacta: se ha comprobado que los cargos huérfanos están en la MISMA moneda que
 * su cabecera (USD), así que no hay conversión de por medio. La condición de moneda va escrita
 * en el `WHERE` de todas formas — si algún día no se cumpliera, la migración prefiere no tocar
 * esa cabecera a dejarla mal.
 *
 * ### Lo que evita que vuelva a pasar
 *
 * Esto limpia; que no se repita lo arregla el listener que se lleva los cargos manuales de una
 * estancia cuando la estancia se borra. Ver `PmsInformacionFinancieraCoherenciaListener`.
 */
final class Version20260812260000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Borra los cargos huérfanos de estancias eliminadas y cuadra cabecera y depósito espejo.';
    }

    public function up(Schema $schema): void
    {
        // 1. Cabeceras afectadas y cuánto hay que descontarles, ANTES de borrar nada.
        $this->addSql(<<<'SQL'
            CREATE TEMPORARY TABLE tmp_huerfanos AS
            SELECT cf.informacion_id AS informacion_id,
                   SUM(cf.total_linea) AS importe
              FROM pms_cargo_financiero cf
              JOIN pms_informacion_financiera i ON i.id = cf.informacion_id
             WHERE cf.evento_id IS NULL
               AND cf.beds24_item_id IS NULL
               AND cf.moneda_id = i.moneda_id
             GROUP BY cf.informacion_id
        SQL);

        // 2. Fuera los huérfanos.
        $this->addSql(<<<'SQL'
            DELETE cf FROM pms_cargo_financiero cf
              JOIN pms_informacion_financiera i ON i.id = cf.informacion_id
             WHERE cf.evento_id IS NULL
               AND cf.beds24_item_id IS NULL
               AND cf.moneda_id = i.moneda_id
        SQL);

        // 3. La cabecera baja lo mismo que se fue.
        $this->addSql(<<<'SQL'
            UPDATE pms_informacion_financiera i
              JOIN tmp_huerfanos t ON t.informacion_id = i.id
               SET i.total_cargos = i.total_cargos - t.importe
        SQL);

        // 4. El depósito espejo del canal persigue al total: se le deja donde lo habría
        //    dejado `PmsPagoOtaAutomaticoService::sincronizar()` en el siguiente recálculo.
        //    Sólo el automático; los pagos reales del huésped no se tocan.
        $this->addSql(<<<'SQL'
            UPDATE pms_pago_financiero p
              JOIN tmp_huerfanos t ON t.informacion_id = p.informacion_id
              JOIN pms_informacion_financiera i ON i.id = p.informacion_id
               SET p.monto = i.total_cargos
             WHERE p.es_automatico = 1
        SQL);

        // 5. Y el total de pagos de la cabecera, que es un agregado almacenado.
        $this->addSql(<<<'SQL'
            UPDATE pms_informacion_financiera i
              JOIN tmp_huerfanos t ON t.informacion_id = i.id
               SET i.total_pagos = (
                   SELECT COALESCE(SUM(p.monto), 0)
                     FROM pms_pago_financiero p
                    WHERE p.informacion_id = i.id
               )
        SQL);

        $this->addSql('DROP TEMPORARY TABLE tmp_huerfanos');
    }

    public function down(Schema $schema): void
    {
        // No hay vuelta atrás: los cargos borrados eran duplicados de un cargo que sí existe
        // (el de Beds24), y recrearlos volvería a inflar el total. Si hiciera falta
        // deshacerlo, se restaura del dump previo al despliegue.
        $this->throwIrreversibleMigrationException(
            'Los cargos huérfanos eran duplicados; restaurar desde el dump previo si hace falta.'
        );
    }
}
