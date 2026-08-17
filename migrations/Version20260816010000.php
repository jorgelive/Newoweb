<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Contabilidad POR MONEDA: la tabla de totales, la imputación de cobros y el cambio de cuadre.
 *
 * ── Qué cambia y por qué ────────────────────────────────────────────────────
 * Hasta ahora la ficha guardaba dos escalares —`total_cargos` y `total_pagos`— con todo
 * convertido a una moneda contable. Un registro sin tipo de cambio **desaparecía del total sin
 * avisar**, y el saldo en la otra moneda era un número que nadie había pactado. La regla nueva
 * es que no se convierte: soles con soles, dólares con dólares.
 *
 * ── Esta migración NO cambia ningún comportamiento ──────────────────────────
 * Es **pura adición**. Las columnas viejas se siguen escribiendo igual mientras dure la
 * transición, así que un `git revert` del código no necesita revertir la base. El `DROP` de
 * `total_cargos`/`total_pagos` va en una migración posterior, cuando esté verificado en
 * producción.
 *
 * ── Las tres piezas ─────────────────────────────────────────────────────────
 *
 * 1. **`pms_finanzas_total_moneda`** — una fila por (ficha, moneda) con movimiento. Tabla y no
 *    columna JSON porque `PmsEstadoPagoEventosService` decide en SQL crudo si una estancia pasa
 *    a `pago-total`, y necesita preguntar «¿queda alguna moneda debiendo?» con un `NOT EXISTS`.
 *
 *    ⚠️ El `ON DELETE CASCADE` **no es opcional**: sin él, borrar una reserva revienta con un
 *    1451 de MySQL. Es el mismo fallo que documenta el `OneToOne` de `PmsInformacionFinanciera`
 *    («ninguna reserva se podía borrar desde el panel»), y esta tabla no entra en ninguna
 *    cascada del ORM porque no está mapeada desde el otro lado, a propósito.
 *
 * 2. **`pms_pago_financiero.moneda_saldada_id`** — a qué deuda se imputa un cobro cuando no es a
 *    la de su propia moneda. Es el caso real de GASUNN: cargos de Booking por US$ 65.97 y un
 *    único cobro de S/ 223.70 por Yape al 3.391. Sin esto, esa ficha diría «debe US$ 65.97» y
 *    «tiene S/ 223.70 a favor», y el huésped pagó y se fue. Medido: 3 fichas de 317.
 *
 * 3. **`pms_informacion_financiera.tipo_cambio`** — el cambio con el que se **cuadra** la
 *    reserva entera. No es contabilidad: es lo que responde «te pago en soles lo que falta,
 *    ¿cuánto es?». Se rellena con la venta del día en que se abrió la ficha, que es el defecto
 *    razonable; el operador puede cambiarlo.
 *
 * ── Va por SQL directo, y aquí sí toca ──────────────────────────────────────
 * Es esquema, más un backfill de un campo que ninguna entidad recalcula al guardarse:
 * `tipo_cambio` de la cabecera no dispara ningún listener de coherencia. La tabla de totales la
 * llena el recalculador en el primer flush de cada ficha; no se puebla aquí a propósito, para
 * que la migración no dependa de una lógica que todavía va a cambiar.
 */
final class Version20260816010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Totales por moneda, imputación de cobros entre monedas y tipo de cambio de cuadre.';
    }

    public function up(Schema $schema): void
    {
        // 1. Los totales, uno por moneda con movimiento.
        $this->addSql(<<<'SQL'
            CREATE TABLE pms_finanzas_total_moneda (
                informacion_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                moneda_id VARCHAR(3) NOT NULL,
                total_cargos NUMERIC(10, 2) DEFAULT '0.00' NOT NULL,
                total_pagos NUMERIC(10, 2) DEFAULT '0.00' NOT NULL,
                INDEX IDX_7B4FE4E78C899341 (informacion_id),
                INDEX IDX_7B4FE4E7B77634D2 (moneda_id),
                PRIMARY KEY(informacion_id, moneda_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE pms_finanzas_total_moneda
                ADD CONSTRAINT FK_7B4FE4E78C899341 FOREIGN KEY (informacion_id)
                REFERENCES pms_informacion_financiera (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE pms_finanzas_total_moneda
                ADD CONSTRAINT FK_7B4FE4E7B77634D2 FOREIGN KEY (moneda_id)
                REFERENCES maestro_moneda (id)
        SQL);

        // 2. La imputación de un cobro a la deuda de otra moneda.
        $this->addSql('ALTER TABLE pms_pago_financiero ADD moneda_saldada_id VARCHAR(3) DEFAULT NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE pms_pago_financiero
                ADD CONSTRAINT FK_F276E47D358C1DCB FOREIGN KEY (moneda_saldada_id)
                REFERENCES maestro_moneda (id)
        SQL);
        $this->addSql('CREATE INDEX IDX_F276E47D358C1DCB ON pms_pago_financiero (moneda_saldada_id)');

        // 3. El cambio de cuadre de la reserva.
        $this->addSql('ALTER TABLE pms_informacion_financiera ADD tipo_cambio NUMERIC(10, 3) DEFAULT NULL');

        // Backfill: la venta del día en que se abrió la ficha, o la última publicada antes de
        // ese día. Es el mismo criterio de `TipocambioManager` —SUNAT no publica fines de semana
        // ni feriados—, y sin él las fichas abiertas un domingo se quedarían sin cuadre.
        $this->addSql(<<<'SQL'
            UPDATE pms_informacion_financiera i
            SET i.tipo_cambio = (
                SELECT tc.venta
                FROM maestro_tipocambio tc
                WHERE tc.moneda_id = 'USD'
                  AND tc.fecha <= DATE(i.created_at)
                ORDER BY tc.fecha DESC
                LIMIT 1
            )
            WHERE i.tipo_cambio IS NULL
        SQL);
    }

    /**
     * Revertir es seguro: nada de lo que se retira era la fuente de verdad todavía.
     *
     * `total_cargos` y `total_pagos` siguen escribiéndose durante toda la transición justo para
     * que esto sea cierto. Lo único que se pierde son las imputaciones que el operador haya
     * hecho a mano (`moneda_saldada_id`), y ésas son decisiones de negocio: si se baja esta
     * migración después de haber imputado cobros, hay que volver a hacerlo.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE pms_finanzas_total_moneda');

        $this->addSql('ALTER TABLE pms_pago_financiero DROP FOREIGN KEY FK_F276E47D358C1DCB');
        $this->addSql('DROP INDEX IDX_F276E47D358C1DCB ON pms_pago_financiero');
        $this->addSql('ALTER TABLE pms_pago_financiero DROP moneda_saldada_id');

        $this->addSql('ALTER TABLE pms_informacion_financiera DROP tipo_cambio');
    }
}
