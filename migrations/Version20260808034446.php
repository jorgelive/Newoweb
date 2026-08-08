<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Módulo Finanzas: enlaces de pago por pasarela + auditoría de webhooks.
 *
 * `fin_enlace_pago.origen_tipo` + `origen_id` son una relación SOFT a propósito (hoy
 * reservas del PMS, mañana tours): por eso NO hay foreign key hacia ninguna tabla de
 * reservas y sí un índice compuesto que la sustituya. Ver `App\Finanzas\Enum\FinOrigenCobro`.
 */
final class Version20260808034446 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Finanzas: tablas fin_enlace_pago y fin_pasarela_webhook_audit (cobros por pasarela).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE fin_enlace_pago (id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', moneda_id VARCHAR(3) NOT NULL, creado_por_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\', token VARCHAR(64) NOT NULL, pasarela VARCHAR(30) NOT NULL, origen_tipo VARCHAR(30) NOT NULL, origen_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', estado VARCHAR(20) NOT NULL, monto_neto NUMERIC(10, 2) NOT NULL, recargo_porcentaje NUMERIC(5, 2) DEFAULT \'0.00\' NOT NULL, monto_total NUMERIC(10, 2) NOT NULL, concepto VARCHAR(255) NOT NULL, origen_referencia VARCHAR(60) DEFAULT NULL, cliente_nombre VARCHAR(150) DEFAULT NULL, cliente_email VARCHAR(180) DEFAULT NULL, cliente_telefono VARCHAR(40) DEFAULT NULL, expira_en DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', pagado_en DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', orden_id VARCHAR(64) DEFAULT NULL, transaccion_uuid VARCHAR(64) DEFAULT NULL, autorizacion_codigo VARCHAR(32) DEFAULT NULL, medio_detalle VARCHAR(60) DEFAULT NULL, respuesta_pasarela JSON DEFAULT NULL, movimiento_generado_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\', notas LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_EA623D89B77634D2 (moneda_id), INDEX IDX_EA623D89FE35D8C4 (creado_por_id), INDEX idx_fin_enlace_pago_origen (origen_tipo, origen_id), INDEX idx_fin_enlace_pago_estado (estado), UNIQUE INDEX uniq_fin_enlace_pago_token (token), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE fin_pasarela_webhook_audit (id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', pasarela VARCHAR(30) NOT NULL, recibido_en DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', remote_ip VARCHAR(45) DEFAULT NULL, payload_raw LONGTEXT DEFAULT NULL, estado VARCHAR(20) NOT NULL, enlace_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\', error_mensaje LONGTEXT DEFAULT NULL, INDEX idx_fin_webhook_audit_recibido (recibido_en), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE fin_enlace_pago ADD CONSTRAINT FK_EA623D89B77634D2 FOREIGN KEY (moneda_id) REFERENCES maestro_moneda (id)');
        $this->addSql('ALTER TABLE fin_enlace_pago ADD CONSTRAINT FK_EA623D89FE35D8C4 FOREIGN KEY (creado_por_id) REFERENCES user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE fin_enlace_pago DROP FOREIGN KEY FK_EA623D89B77634D2');
        $this->addSql('ALTER TABLE fin_enlace_pago DROP FOREIGN KEY FK_EA623D89FE35D8C4');
        $this->addSql('DROP TABLE fin_enlace_pago');
        $this->addSql('DROP TABLE fin_pasarela_webhook_audit');
    }
}
