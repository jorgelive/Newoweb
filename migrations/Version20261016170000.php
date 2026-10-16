<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Los pagos del cliente a cuenta de un expediente de turismo (`cotizacion_pago`). Ver
 * `CotizacionPago` y docs/Cotizaciones.md §6.a.
 *
 * Tabla nueva y vacía. La FK al expediente va SIN `ON DELETE`: un pago es un documento contable y
 * un expediente con pagos no se debe poder borrar sin decidir antes qué pasa con ese dinero.
 */
final class Version20261016170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pagos de los expedientes de turismo (cotizacion_pago)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE cotizacion_pago (id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)', file_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)', pasajero_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)', registrado_por_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)', anulado_por_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)', propuesta INT DEFAULT NULL, monto NUMERIC(12, 2) NOT NULL, moneda VARCHAR(3) NOT NULL, tipo_cambio NUMERIC(10, 4) DEFAULT NULL, medio VARCHAR(30) NOT NULL, fecha_pago DATE NOT NULL COMMENT '(DC2Type:date_immutable)', referencia VARCHAR(100) DEFAULT NULL, nota LONGTEXT DEFAULT NULL, enlace_pago_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)', anulado TINYINT(1) DEFAULT 0 NOT NULL, motivo_anulacion LONGTEXT DEFAULT NULL, anulado_en DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_3955BEFF93CB796C (file_id), INDEX IDX_3955BEFF704716FE (pasajero_id), INDEX IDX_3955BEFFEC7D893C (registrado_por_id), INDEX IDX_3955BEFF79D13EAA (anulado_por_id), INDEX idx_cotizacion_pago_fecha (fecha_pago), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("ALTER TABLE cotizacion_pago ADD CONSTRAINT FK_3955BEFF93CB796C FOREIGN KEY (file_id) REFERENCES cotizacion_file (id)");
        $this->addSql("ALTER TABLE cotizacion_pago ADD CONSTRAINT FK_3955BEFF704716FE FOREIGN KEY (pasajero_id) REFERENCES cotizacion_file_pasajero (id) ON DELETE SET NULL");
        $this->addSql("ALTER TABLE cotizacion_pago ADD CONSTRAINT FK_3955BEFFEC7D893C FOREIGN KEY (registrado_por_id) REFERENCES user (id) ON DELETE SET NULL");
        $this->addSql("ALTER TABLE cotizacion_pago ADD CONSTRAINT FK_3955BEFF79D13EAA FOREIGN KEY (anulado_por_id) REFERENCES user (id) ON DELETE SET NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cotizacion_pago');
    }
}
