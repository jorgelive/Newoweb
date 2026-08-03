<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La comisión del pago pasa de IMPORTE a PORCENTAJE.
 *
 * Guardar el porcentaje es más estable: si luego se corrige el monto, el recargo sigue siendo
 * correcto sin recalcularlo a mano. El importe se deriva (`PmsPagoFinanciero::getMontoComision()`),
 * igual que el total cobrado.
 *
 * Los datos existentes se convierten con `comision / monto * 100`, no se descartan: un pago de
 * 100 con comisión 5.50 pasa a 5.50%. Los de monto 0 no admiten conversión y quedan en NULL.
 */
final class Version20260803014651 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'pms_pago_financiero.comision (importe) pasa a comision_porcentaje (%), convirtiendo los datos existentes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_pago_financiero ADD comision_porcentaje NUMERIC(5, 2) DEFAULT NULL');

        // Importe -> porcentaje. LEAST(999.99) protege el NUMERIC(5,2) ante datos anómalos.
        $this->addSql('
            UPDATE pms_pago_financiero
            SET comision_porcentaje = LEAST(ROUND(comision / monto * 100, 2), 999.99)
            WHERE comision IS NOT NULL AND monto IS NOT NULL AND monto <> 0
        ');

        $this->addSql('ALTER TABLE pms_pago_financiero DROP comision');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_pago_financiero ADD comision NUMERIC(10, 2) DEFAULT NULL');
        $this->addSql('
            UPDATE pms_pago_financiero
            SET comision = ROUND(monto * comision_porcentaje / 100, 2)
            WHERE comision_porcentaje IS NOT NULL AND monto IS NOT NULL
        ');
        $this->addSql('ALTER TABLE pms_pago_financiero DROP comision_porcentaje');
    }
}
