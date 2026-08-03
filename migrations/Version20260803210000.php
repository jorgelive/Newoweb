<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Regularización histórica: reservas marcadas como PAGADAS que no tenían ningún pago registrado.
 *
 * El módulo financiero es posterior a esas reservas, así que su estado de pago vivía sólo en
 * `pms_evento_calendario.estado_pago_id`. Al estrenar cargos y pagos aparecían con saldo
 * pendiente pese a estar cobradas, y ese saldo falso contamina el panel y las variables de
 * mensajería (§12.0.3).
 *
 * Se les registra un pago en EFECTIVO por lo que falta, con fecha el último día del mes de
 * llegada, de forma que el saldo quede en cero.
 */
final class Version20260803210000 extends AbstractMigration
{
    /** Marca para poder revertir sólo lo que crea esta migración. */
    private const NOTA = 'Regularización: reserva marcada como pagada antes del módulo financiero.';

    public function getDescription(): string
    {
        return 'Regulariza con un pago en efectivo las reservas en estado pago-total que no tenían ningún pago (saldo 0).';
    }

    public function up(Schema $schema): void
    {
        $nota = $this->connection->quote(self::NOTA);

        // Se inserta la DIFERENCIA (cargos - pagos), no el total: si la reserva ya tuviera un
        // abono parcial, sumarle el importe entero la dejaría con saldo negativo.
        //
        // Criterio de "pagada": NINGUNA de sus estancias está fuera de 'pago-total'. Con una
        // sola estancia —el caso normal— equivale a que esa estancia lo esté; en una reserva
        // agrupada exige que lo estén todas, que es lo prudente.
        //
        // Las OTA de pago total (airbnb/vrbo) ya quedaron cuadradas por Version20260803190000,
        // así que su diferencia es 0 y no entran aquí.
        $this->addSql(<<<SQL
            INSERT INTO pms_pago_financiero
                (id, informacion_id, moneda_id, monto, tipo_cambio, medio_pago, fecha_pago,
                 referencia, notas, comision_porcentaje, es_automatico, created_at, updated_at)
            SELECT
                UUID_TO_BIN(UUID()),
                i.id,
                i.moneda_id,
                CAST(i.total_cargos AS DECIMAL(10,2)) - CAST(i.total_pagos AS DECIMAL(10,2)),
                NULL,
                'efectivo',
                COALESCE(LAST_DAY(r.fecha_llegada), CURDATE()),
                NULL,
                {$nota},
                '0.00',
                0,
                NOW(),
                NULL
            FROM pms_informacion_financiera i
            INNER JOIN pms_reserva r ON r.id = i.reserva_id
            WHERE CAST(i.total_cargos AS DECIMAL(10,2)) - CAST(i.total_pagos AS DECIMAL(10,2)) > 0
              AND EXISTS (
                  SELECT 1 FROM pms_evento_calendario e WHERE e.reserva_id = r.id
              )
              AND NOT EXISTS (
                  SELECT 1 FROM pms_evento_calendario e
                  WHERE e.reserva_id = r.id AND e.estado_pago_id <> 'pago-total'
              )
            SQL);

        // El rollup vive en SQL crudo y no se dispara desde una migración: la caché de totales
        // se cuadra aquí. Tras el pago, pagos == cargos.
        $this->addSql(<<<SQL
            UPDATE pms_informacion_financiera i
            SET i.total_pagos = i.total_cargos
            WHERE EXISTS (
                SELECT 1 FROM pms_pago_financiero p
                WHERE p.informacion_id = i.id AND p.notas = {$nota}
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $nota = $this->connection->quote(self::NOTA);

        // Sólo los pagos que creó esta migración: se identifican por su nota. Si el operador
        // editó alguno y le cambió la nota, se queda — es un dato suyo.
        $this->addSql("DELETE FROM pms_pago_financiero WHERE notas = {$nota}");

        $this->addSql(<<<'SQL'
            UPDATE pms_informacion_financiera i
            SET i.total_pagos = COALESCE((
                SELECT SUM(p.monto) FROM pms_pago_financiero p WHERE p.informacion_id = i.id
            ), '0.00')
            SQL);
    }
}
