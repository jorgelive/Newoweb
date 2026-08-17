<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `operacion_pago`: los pagos a cuenta hechos al proveedor por una Orden de Servicio.
 *
 * El saldo NO se guarda: se calcula (negociado − Σ pagos) por moneda en
 * `OperacionOrdenServicio::getTotalesPorMoneda()`. Cada pago lleva su moneda porque una orden
 * puede mezclar divisas y no se convierte.
 */
final class Version20260817200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'operacion_pago: pagos a cuenta al proveedor por orden.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE operacion_pago (
                id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                orden_servicio_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                moneda VARCHAR(3) NOT NULL,
                monto NUMERIC(12, 2) NOT NULL,
                fecha DATE NOT NULL COMMENT '(DC2Type:date_immutable)',
                notas LONGTEXT DEFAULT NULL,
                usuario_nombre VARCHAR(100) DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_pago_orden (orden_servicio_id),
                INDEX IDX_130E2D17B00B2B2D (moneda),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE operacion_pago
            ADD CONSTRAINT FK_PAGO_ORDEN FOREIGN KEY (orden_servicio_id)
            REFERENCES operacion_orden_servicio (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE operacion_pago
            ADD CONSTRAINT FK_PAGO_MONEDA FOREIGN KEY (moneda)
            REFERENCES maestro_moneda (id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_pago DROP FOREIGN KEY FK_PAGO_ORDEN');
        $this->addSql('ALTER TABLE operacion_pago DROP FOREIGN KEY FK_PAGO_MONEDA');
        $this->addSql('DROP TABLE operacion_pago');
    }
}
