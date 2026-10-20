<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El horario extra pasa a su tipo de cargo propio (`entrada_temprana` / `salida_tardia`).
 *
 * Iban como `servicio` los que abre `PmsCargosAutomaticosService` (descripción canónica «Entrada
 * temprana (noche bloqueada)…»), y como `otro` los que el equipo escribía a mano («Early check in»,
 * 2 en producción el 20/10/2026): faltaba el tipo. Sólo los manuales —sin `beds24_item_id`—: los del
 * canal los clasifica `PmsTipoCargo::desdeBeds24()`.
 *
 * Por SQL: el tipo no entra en los totales por moneda, así que los listeners de coherencia no
 * tienen nada que recalcular.
 */
final class Version20261020150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'pms_cargo_financiero: el horario extra con su tipo de cargo propio';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE pms_cargo_financiero SET tipo_cargo = 'entrada_temprana'
            WHERE beds24_item_id IS NULL AND (
                (tipo_cargo = 'servicio' AND descripcion LIKE 'Entrada temprana (noche bloqueada)%')
                OR (tipo_cargo = 'otro' AND (LOWER(descripcion) LIKE 'early check%' OR LOWER(descripcion) LIKE 'entrada temprana%'))
            )");
        $this->addSql("UPDATE pms_cargo_financiero SET tipo_cargo = 'salida_tardia'
            WHERE beds24_item_id IS NULL AND (
                (tipo_cargo = 'servicio' AND descripcion LIKE 'Salida tardía (noche bloqueada)%')
                OR (tipo_cargo = 'otro' AND (LOWER(descripcion) LIKE 'late check%' OR LOWER(descripcion) LIKE 'salida tard%'))
            )");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE pms_cargo_financiero SET tipo_cargo = 'servicio' WHERE tipo_cargo IN ('entrada_temprana', 'salida_tardia')");
    }
}
