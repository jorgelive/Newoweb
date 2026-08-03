<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Backfill de cabeceras financieras.
 *
 * A partir de ahora PmsInformacionFinancieraCoherenciaListener crea la cabecera junto con
 * cada PmsReserva nueva, pero las reservas YA EXISTENTES se quedaron sin ella: en el panel
 * financiero no habría dónde registrar cargos ni pagos (el caso de las reservas directas,
 * que nunca reciben invoiceItems de Beds24).
 *
 * Se crean con moneda USD (la base del PMS) y totales en 0: el listener de coherencia
 * recalculará los importes reales en cuanto se les cuelgue el primer cargo o pago.
 */
final class Version20260802235500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill: crea la cabecera financiera (pms_informacion_financiera) de las reservas que aún no la tienen.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO pms_informacion_financiera
                (id, reserva_id, moneda_id, total_cargos, total_pagos, last_synced_at, created_at, updated_at)
            SELECT
                UUID_TO_BIN(UUID()), r.id, 'USD', '0.00', '0.00', NULL, NOW(), NULL
            FROM pms_reserva r
            LEFT JOIN pms_informacion_financiera i ON i.reserva_id = r.id
            WHERE i.id IS NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Sólo se revierten las cabeceras vacías creadas por este backfill: las que ya
        // tienen cargos o pagos colgando son datos reales y no se tocan.
        $this->addSql(<<<'SQL'
            DELETE i FROM pms_informacion_financiera i
            LEFT JOIN pms_cargo_financiero c ON c.informacion_id = i.id
            LEFT JOIN pms_pago_financiero p ON p.informacion_id = i.id
            WHERE c.id IS NULL AND p.id IS NULL
            SQL);
    }
}
