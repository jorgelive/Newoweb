<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sincronización financiera Beds24: tablas de información financiera (cabecera por reserva),
 * cargos financieros (invoiceItems) y cola de pull en-demanda. Registra además el endpoint
 * GET_INVOICES (bookings/invoices) que consume el cron/pull.
 */
final class Version20260802133405 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Información financiera Beds24 (invoiceItems): pms_informacion_financiera, pms_cargo_financiero, cola pms_beds24_invoice_receive_queue y endpoint GET_INVOICES.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE pms_beds24_invoice_receive_queue (id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', config_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', endpoint_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', target_book_id VARCHAR(50) NOT NULL, run_at DATETIME NOT NULL, status VARCHAR(20) NOT NULL, retry_count SMALLINT DEFAULT 0 NOT NULL, max_attempts SMALLINT DEFAULT 3 NOT NULL, locked_at DATETIME DEFAULT NULL, locked_by VARCHAR(100) DEFAULT NULL, last_request_raw LONGTEXT DEFAULT NULL, last_response_raw LONGTEXT DEFAULT NULL, execution_result JSON DEFAULT NULL, last_http_code SMALLINT DEFAULT NULL, failed_reason VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_4090FFC524DB0683 (config_id), INDEX IDX_4090FFC521AF7E36 (endpoint_id), INDEX idx_pms_invoice_receive_worker (status, run_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE pms_cargo_financiero (id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', informacion_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', beds24_item_id VARCHAR(50) NOT NULL, beds24_booking_id VARCHAR(50) DEFAULT NULL, beds24_invoice_id VARCHAR(50) DEFAULT NULL, beds24_invoicee_id VARCHAR(50) DEFAULT NULL, tipo VARCHAR(20) DEFAULT NULL, sub_tipo INT DEFAULT NULL, descripcion LONGTEXT DEFAULT NULL, estado VARCHAR(50) DEFAULT NULL, cantidad NUMERIC(10, 2) DEFAULT NULL, monto NUMERIC(10, 2) DEFAULT NULL, total_linea NUMERIC(10, 2) DEFAULT NULL, tasa_iva NUMERIC(5, 2) DEFAULT NULL, creado_por_beds24 VARCHAR(50) DEFAULT NULL, fecha_creacion_beds24 DATETIME DEFAULT NULL, fecha_factura DATE DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_F14599938C899341 (informacion_id), UNIQUE INDEX uq_cargo_info_item (informacion_id, beds24_item_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE pms_informacion_financiera (id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', reserva_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', moneda VARCHAR(10) DEFAULT NULL, total_cargos NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL, total_pagos NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL, last_synced_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_2AFB3104D67139E8 (reserva_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE pms_beds24_invoice_receive_queue ADD CONSTRAINT FK_4090FFC524DB0683 FOREIGN KEY (config_id) REFERENCES exchange_beds24_config (id)');
        $this->addSql('ALTER TABLE pms_beds24_invoice_receive_queue ADD CONSTRAINT FK_4090FFC521AF7E36 FOREIGN KEY (endpoint_id) REFERENCES exchange_exchange_endpoint (id)');
        $this->addSql('ALTER TABLE pms_cargo_financiero ADD CONSTRAINT FK_F14599938C899341 FOREIGN KEY (informacion_id) REFERENCES pms_informacion_financiera (id)');
        $this->addSql('ALTER TABLE pms_informacion_financiera ADD CONSTRAINT FK_2AFB3104D67139E8 FOREIGN KEY (reserva_id) REFERENCES pms_reserva (id)');

        // Endpoint que consume el pull/cron de finanzas: GET https://.../bookings/invoices?bookingId=...
        // Idempotente: la constraint uq_provider_accion evita duplicados si se re-ejecuta.
        $this->addSql("INSERT INTO exchange_exchange_endpoint (id, provider, nombre, accion, endpoint, metodo, activo, created_at)
            SELECT UUID_TO_BIN(UUID()), 'beds24', 'Obtener facturas de Beds24', 'GET_INVOICES', 'bookings/invoices', 'GET', 1, NOW()
            WHERE NOT EXISTS (
                SELECT 1 FROM exchange_exchange_endpoint WHERE provider = 'beds24' AND accion = 'GET_INVOICES'
            )");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM exchange_exchange_endpoint WHERE provider = 'beds24' AND accion = 'GET_INVOICES'");
        $this->addSql('ALTER TABLE pms_beds24_invoice_receive_queue DROP FOREIGN KEY FK_4090FFC524DB0683');
        $this->addSql('ALTER TABLE pms_beds24_invoice_receive_queue DROP FOREIGN KEY FK_4090FFC521AF7E36');
        $this->addSql('ALTER TABLE pms_cargo_financiero DROP FOREIGN KEY FK_F14599938C899341');
        $this->addSql('ALTER TABLE pms_informacion_financiera DROP FOREIGN KEY FK_2AFB3104D67139E8');
        $this->addSql('DROP TABLE pms_beds24_invoice_receive_queue');
        $this->addSql('DROP TABLE pms_cargo_financiero');
        $this->addSql('DROP TABLE pms_informacion_financiera');
    }
}
