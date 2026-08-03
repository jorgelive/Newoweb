<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Depósito automático de las OTA que cobran al huésped (Airbnb, VRBO).
 *
 * En esos canales el dinero no lo cobramos nosotros: la OTA cobra y deposita en la cuenta
 * bancaria. No había ningún pago que registrar, así que esas reservas figuraban impagadas
 * aunque estuvieran cobradas al 100 %.
 *
 * 1. Añade `es_automatico` para poder distinguir (y volver a encontrar) ese depósito.
 * 2. Backfill de las que ya estaban huérfanas, dejando el saldo en CERO.
 */
final class Version20260803190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pago automático de OTA de pago total (airbnb/vrbo): columna es_automatico + backfill con saldo 0.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE pms_pago_financiero '
            . 'ADD es_automatico TINYINT(1) DEFAULT 0 NOT NULL'
        );

        // Backfill. Se inserta la DIFERENCIA (cargos - pagos) y no el total: una reserva que ya
        // tuviera un pago parcial registrado a mano quedaría con saldo negativo si se le sumara
        // el importe entero. Así el saldo queda en cero en los dos casos.
        //
        // Sólo entran las que tienen algo pendiente (diferencia > 0): sin cargos todavía no hay
        // depósito que inventar, y las que ya cuadran no se tocan.
        $this->addSql(<<<'SQL'
            INSERT INTO pms_pago_financiero
                (id, informacion_id, moneda_id, monto, tipo_cambio, medio_pago, fecha_pago,
                 referencia, notas, comision_porcentaje, es_automatico, created_at, updated_at)
            SELECT
                UUID_TO_BIN(UUID()),
                i.id,
                i.moneda_id,
                CAST(i.total_cargos AS DECIMAL(10,2)) - CAST(i.total_pagos AS DECIMAL(10,2)),
                NULL,
                'transferencia_bancaria',
                COALESCE(DATE_ADD(r.fecha_llegada, INTERVAL 1 DAY), CURDATE()),
                NULL,
                'Depósito automático del canal (cobra la OTA).',
                '0.00',
                1,
                NOW(),
                NULL
            FROM pms_informacion_financiera i
            INNER JOIN pms_reserva r ON r.id = i.reserva_id
            WHERE r.channel_id IN ('airbnb', 'vrbo')
              AND CAST(i.total_cargos AS DECIMAL(10,2)) - CAST(i.total_pagos AS DECIMAL(10,2)) > 0
              AND NOT EXISTS (
                  SELECT 1 FROM pms_pago_financiero p
                  WHERE p.informacion_id = i.id AND p.es_automatico = 1
              )
            SQL);

        // El rollup vive en SQL crudo y no se dispara desde una migración, así que la caché de
        // totales se cuadra aquí mismo: tras el backfill, pagos == cargos.
        $this->addSql(<<<'SQL'
            UPDATE pms_informacion_financiera i
            INNER JOIN pms_reserva r ON r.id = i.reserva_id
            SET i.total_pagos = i.total_cargos
            WHERE r.channel_id IN ('airbnb', 'vrbo')
              AND EXISTS (
                  SELECT 1 FROM pms_pago_financiero p
                  WHERE p.informacion_id = i.id AND p.es_automatico = 1
              )
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Se borran SÓLO los depósitos automáticos: si el operador editó alguno, el listener
        // ya le habrá quitado la marca y aquí no se toca.
        $this->addSql('DELETE FROM pms_pago_financiero WHERE es_automatico = 1');

        $this->addSql(<<<'SQL'
            UPDATE pms_informacion_financiera i
            SET i.total_pagos = COALESCE((
                SELECT SUM(p.monto) FROM pms_pago_financiero p WHERE p.informacion_id = i.id
            ), '0.00')
            SQL);

        $this->addSql('ALTER TABLE pms_pago_financiero DROP es_automatico');
    }
}
