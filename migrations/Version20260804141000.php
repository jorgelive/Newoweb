<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Semillas i18n del globo que explica un ítem con candado (HuespedGuiaView).
 *
 * Son el espejo en el front de PmsGuiaMensajes::bloqueo(), que resuelve lo mismo
 * para los placeholders del cuerpo: si un texto cambia aquí, mirar también allí
 * o el ítem y su interior contarán historias distintas.
 *
 * `gui_bloqueo_fecha` lleva `{{ fecha }}`: lo interpola `maestroStore.t()`.
 *
 * Idempotente y no destructiva, igual que Version20260803235500.
 */
final class Version20260804141000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Semillas i18n del aviso de ítem bloqueado en la guía del huésped.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO pax_ui_i18n (id, scope, contenido, created_at, updated_at) VALUES
            ('gui_bloqueo_confirmar', 'guia', '[{"content": "Disponible al confirmar tu reserva", "language": "es"}, {"content": "Available once your booking is confirmed", "language": "en"}, {"content": "Disponível ao confirmar a sua reserva", "language": "pt"}, {"content": "Disponible après confirmation de votre réservation", "language": "fr"}, {"content": "Disponibile alla conferma della prenotazione", "language": "it"}, {"content": "Verfügbar nach Bestätigung Ihrer Buchung", "language": "de"}, {"content": "Beschikbaar zodra je boeking is bevestigd", "language": "nl"}]', NOW(), NOW()),
            ('gui_bloqueo_fecha', 'guia', '[{"content": "Disponible el {{ fecha }}", "language": "es"}, {"content": "Available on {{ fecha }}", "language": "en"}, {"content": "Disponível em {{ fecha }}", "language": "pt"}, {"content": "Disponible le {{ fecha }}", "language": "fr"}, {"content": "Disponibile il {{ fecha }}", "language": "it"}, {"content": "Verfügbar am {{ fecha }}", "language": "de"}, {"content": "Beschikbaar op {{ fecha }}", "language": "nl"}]', NOW(), NOW()),
            ('gui_bloqueo_pronto', 'guia', '[{"content": "Disponible pronto", "language": "es"}, {"content": "Available soon", "language": "en"}, {"content": "Disponível em breve", "language": "pt"}, {"content": "Bientôt disponible", "language": "fr"}, {"content": "Disponibile a breve", "language": "it"}, {"content": "Bald verfügbar", "language": "de"}, {"content": "Binnenkort beschikbaar", "language": "nl"}]', NOW(), NOW()),
            ('gui_bloqueo_expirada', 'guia', '[{"content": "Ya no disponible: la estancia terminó", "language": "es"}, {"content": "No longer available: the stay has ended", "language": "en"}, {"content": "Já não disponível: a estadia terminou", "language": "pt"}, {"content": "Plus disponible : le séjour est terminé", "language": "fr"}, {"content": "Non più disponibile: il soggiorno è terminato", "language": "it"}, {"content": "Nicht mehr verfügbar: Der Aufenthalt ist beendet", "language": "de"}, {"content": "Niet meer beschikbaar: het verblijf is afgelopen", "language": "nl"}]', NOW(), NOW())
            AS nuevo
            ON DUPLICATE KEY UPDATE
                contenido = nuevo.contenido,
                updated_at = NOW()
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM pax_ui_i18n WHERE id IN ('gui_bloqueo_confirmar', 'gui_bloqueo_fecha', 'gui_bloqueo_pronto', 'gui_bloqueo_expirada')");
    }
}
