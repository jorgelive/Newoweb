<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Semillas i18n del estado de cuenta del huésped (PmsReservaView).
 *
 * `res_recargo_nota` lleva `{{ pct }}`: la vista lo interpola con el porcentaje
 * real (RECARGO_TARJETA_PCT), así el texto no se desactualiza si cambia la comisión.
 *
 * Idempotente y no destructiva, igual que Version20260803140000.
 */
final class Version20260804001500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Semillas i18n del estado de cuenta del huésped (total, adelanto, saldo, tarjeta).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO pax_ui_i18n (id, scope, contenido, created_at, updated_at) VALUES
            ('res_estado_cuenta', 'reserva', '[{"content": "Estado de cuenta", "language": "es"}, {"content": "Account summary", "language": "en"}, {"content": "Estado da conta", "language": "pt"}, {"content": "État du compte", "language": "fr"}, {"content": "Estratto conto", "language": "it"}, {"content": "Kontoübersicht", "language": "de"}, {"content": "Rekeningoverzicht", "language": "nl"}]', NOW(), NOW()),
            ('res_al_dia', 'reserva', '[{"content": "Al día", "language": "es"}, {"content": "Paid up", "language": "en"}, {"content": "Em dia", "language": "pt"}, {"content": "À jour", "language": "fr"}, {"content": "In regola", "language": "it"}, {"content": "Beglichen", "language": "de"}, {"content": "Voldaan", "language": "nl"}]', NOW(), NOW()),
            ('res_saldo_pendiente', 'reserva', '[{"content": "Saldo pendiente", "language": "es"}, {"content": "Balance due", "language": "en"}, {"content": "Saldo pendente", "language": "pt"}, {"content": "Solde à régler", "language": "fr"}, {"content": "Saldo in sospeso", "language": "it"}, {"content": "Offener Betrag", "language": "de"}, {"content": "Openstaand saldo", "language": "nl"}]', NOW(), NOW()),
            ('res_pagado_pct', 'reserva', '[{"content": "pagado", "language": "es"}, {"content": "paid", "language": "en"}, {"content": "pago", "language": "pt"}, {"content": "payé", "language": "fr"}, {"content": "pagato", "language": "it"}, {"content": "bezahlt", "language": "de"}, {"content": "betaald", "language": "nl"}]', NOW(), NOW()),
            ('res_total_reserva', 'reserva', '[{"content": "Total de la reserva", "language": "es"}, {"content": "Booking total", "language": "en"}, {"content": "Total da reserva", "language": "pt"}, {"content": "Total de la réservation", "language": "fr"}, {"content": "Totale della prenotazione", "language": "it"}, {"content": "Gesamtbetrag der Buchung", "language": "de"}, {"content": "Totaal van de boeking", "language": "nl"}]', NOW(), NOW()),
            ('res_adelanto_pagado', 'reserva', '[{"content": "Adelanto pagado", "language": "es"}, {"content": "Deposit paid", "language": "en"}, {"content": "Adiantamento pago", "language": "pt"}, {"content": "Acompte versé", "language": "fr"}, {"content": "Acconto versato", "language": "it"}, {"content": "Gezahlte Anzahlung", "language": "de"}, {"content": "Betaalde aanbetaling", "language": "nl"}]', NOW(), NOW()),
            ('res_saldo', 'reserva', '[{"content": "Saldo por pagar", "language": "es"}, {"content": "Balance to pay", "language": "en"}, {"content": "Saldo a pagar", "language": "pt"}, {"content": "Solde à payer", "language": "fr"}, {"content": "Saldo da pagare", "language": "it"}, {"content": "Zu zahlender Betrag", "language": "de"}, {"content": "Te betalen saldo", "language": "nl"}]', NOW(), NOW()),
            ('res_saldo_tarjeta', 'reserva', '[{"content": "Pagando con tarjeta", "language": "es"}, {"content": "Paying by card", "language": "en"}, {"content": "Pagando com cartão", "language": "pt"}, {"content": "En payant par carte", "language": "fr"}, {"content": "Pagando con carta", "language": "it"}, {"content": "Bei Kartenzahlung", "language": "de"}, {"content": "Bij betaling per kaart", "language": "nl"}]', NOW(), NOW()),
            ('res_recargo_nota', 'reserva', '[{"content": "Incluye {{ pct }}% de comisión", "language": "es"}, {"content": "Includes {{ pct }}% card fee", "language": "en"}, {"content": "Inclui {{ pct }}% de comissão", "language": "pt"}, {"content": "Comprend {{ pct }} % de frais", "language": "fr"}, {"content": "Include il {{ pct }}% di commissione", "language": "it"}, {"content": "Inklusive {{ pct }} % Gebühr", "language": "de"}, {"content": "Inclusief {{ pct }}% toeslag", "language": "nl"}]', NOW(), NOW()),
            ('res_todo_pagado', 'reserva', '[{"content": "¡Tu reserva está totalmente pagada. Gracias!", "language": "es"}, {"content": "Your booking is fully paid. Thank you!", "language": "en"}, {"content": "A sua reserva está totalmente paga. Obrigado!", "language": "pt"}, {"content": "Votre réservation est entièrement payée. Merci !", "language": "fr"}, {"content": "La tua prenotazione è interamente pagata. Grazie!", "language": "it"}, {"content": "Ihre Buchung ist vollständig bezahlt. Vielen Dank!", "language": "de"}, {"content": "Je boeking is volledig betaald. Bedankt!", "language": "nl"}]', NOW(), NOW())
            AS nuevo
            ON DUPLICATE KEY UPDATE
                contenido  = IF(JSON_LENGTH(pax_ui_i18n.contenido) > 0, pax_ui_i18n.contenido, nuevo.contenido),
                updated_at = NOW()
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM pax_ui_i18n WHERE id IN (
                'res_estado_cuenta', 'res_al_dia', 'res_saldo_pendiente', 'res_pagado_pct',
                'res_total_reserva', 'res_adelanto_pagado', 'res_saldo', 'res_saldo_tarjeta',
                'res_recargo_nota', 'res_todo_pagado'
            )
        SQL);
    }
}
