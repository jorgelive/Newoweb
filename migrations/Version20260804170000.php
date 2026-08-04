<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Semillas i18n del desglose del estado de cuenta (PmsReservaView).
 *
 * Tipos de cargo (`PmsTipoCargo`) y medios de pago (`PmsMedioPago`). El backend
 * manda el VALOR del enum, no una etiqueta: las descripciones de los cargos
 * llegan de Beds24 en un solo idioma y no se pueden traducir, mientras que el
 * tipo sí tiene una traducción estable por idioma.
 *
 * Las claves siguen el patrón `res_cargo_<valor>` / `res_medio_<valor>`, que es
 * el que compone la vista. Si se añade un caso al enum, hay que sembrar aquí su
 * etiqueta y añadirla al mapa de respaldo de PmsReservaView.
 *
 * Idempotente y no destructiva, igual que Version20260804001500.
 */
final class Version20260804170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Semillas i18n de tipos de cargo y medios de pago del estado de cuenta del huesped.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO pax_ui_i18n (id, scope, contenido, created_at, updated_at) VALUES
            ('res_cargo_alojamiento', 'reserva', '[{"content": "Alojamiento", "language": "es"}, {"content": "Accommodation", "language": "en"}, {"content": "Alojamento", "language": "pt"}, {"content": "Hébergement", "language": "fr"}, {"content": "Alloggio", "language": "it"}, {"content": "Unterkunft", "language": "de"}, {"content": "Accommodatie", "language": "nl"}]', NOW(), NOW()),
            ('res_cargo_limpieza', 'reserva', '[{"content": "Limpieza", "language": "es"}, {"content": "Cleaning", "language": "en"}, {"content": "Limpeza", "language": "pt"}, {"content": "Ménage", "language": "fr"}, {"content": "Pulizia", "language": "it"}, {"content": "Reinigung", "language": "de"}, {"content": "Schoonmaak", "language": "nl"}]', NOW(), NOW()),
            ('res_cargo_servicio', 'reserva', '[{"content": "Servicio", "language": "es"}, {"content": "Service", "language": "en"}, {"content": "Serviço", "language": "pt"}, {"content": "Service", "language": "fr"}, {"content": "Servizio", "language": "it"}, {"content": "Service", "language": "de"}, {"content": "Service", "language": "nl"}]', NOW(), NOW()),
            ('res_cargo_penalizacion', 'reserva', '[{"content": "Penalización", "language": "es"}, {"content": "Cancellation fee", "language": "en"}, {"content": "Penalização", "language": "pt"}, {"content": "Frais d''annulation", "language": "fr"}, {"content": "Penale", "language": "it"}, {"content": "Stornogebühr", "language": "de"}, {"content": "Annuleringskosten", "language": "nl"}]', NOW(), NOW()),
            ('res_cargo_otro', 'reserva', '[{"content": "Otros", "language": "es"}, {"content": "Other", "language": "en"}, {"content": "Outros", "language": "pt"}, {"content": "Autres", "language": "fr"}, {"content": "Altro", "language": "it"}, {"content": "Sonstiges", "language": "de"}, {"content": "Overig", "language": "nl"}]', NOW(), NOW()),
            ('res_medio_efectivo', 'reserva', '[{"content": "Efectivo", "language": "es"}, {"content": "Cash", "language": "en"}, {"content": "Dinheiro", "language": "pt"}, {"content": "Espèces", "language": "fr"}, {"content": "Contanti", "language": "it"}, {"content": "Bargeld", "language": "de"}, {"content": "Contant", "language": "nl"}]', NOW(), NOW()),
            ('res_medio_plin_yape', 'reserva', '[{"content": "Plin / Yape", "language": "es"}, {"content": "Plin / Yape", "language": "en"}, {"content": "Plin / Yape", "language": "pt"}, {"content": "Plin / Yape", "language": "fr"}, {"content": "Plin / Yape", "language": "it"}, {"content": "Plin / Yape", "language": "de"}, {"content": "Plin / Yape", "language": "nl"}]', NOW(), NOW()),
            ('res_medio_tarjeta_credito', 'reserva', '[{"content": "Tarjeta de crédito", "language": "es"}, {"content": "Credit card", "language": "en"}, {"content": "Cartão de crédito", "language": "pt"}, {"content": "Carte de crédit", "language": "fr"}, {"content": "Carta di credito", "language": "it"}, {"content": "Kreditkarte", "language": "de"}, {"content": "Creditcard", "language": "nl"}]', NOW(), NOW()),
            ('res_medio_western_union', 'reserva', '[{"content": "Western Union", "language": "es"}, {"content": "Western Union", "language": "en"}, {"content": "Western Union", "language": "pt"}, {"content": "Western Union", "language": "fr"}, {"content": "Western Union", "language": "it"}, {"content": "Western Union", "language": "de"}, {"content": "Western Union", "language": "nl"}]', NOW(), NOW()),
            ('res_medio_transferencia_bancaria', 'reserva', '[{"content": "Transferencia bancaria", "language": "es"}, {"content": "Bank transfer", "language": "en"}, {"content": "Transferência bancária", "language": "pt"}, {"content": "Virement bancaire", "language": "fr"}, {"content": "Bonifico bancario", "language": "it"}, {"content": "Banküberweisung", "language": "de"}, {"content": "Bankoverschrijving", "language": "nl"}]', NOW(), NOW()),
            ('res_medio_paypal', 'reserva', '[{"content": "PayPal", "language": "es"}, {"content": "PayPal", "language": "en"}, {"content": "PayPal", "language": "pt"}, {"content": "PayPal", "language": "fr"}, {"content": "PayPal", "language": "it"}, {"content": "PayPal", "language": "de"}, {"content": "PayPal", "language": "nl"}]', NOW(), NOW())
            AS nuevo
            ON DUPLICATE KEY UPDATE
                contenido = nuevo.contenido,
                updated_at = NOW()
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM pax_ui_i18n WHERE id LIKE 'res_cargo_%' OR id LIKE 'res_medio_%'");
    }
}
