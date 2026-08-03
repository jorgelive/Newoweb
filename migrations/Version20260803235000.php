<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Semillas i18n de la guía del huésped (`HuespedGuiaView.vue`).
 *
 * Avisos de la ventana de acceso, candado de ítem y errores de carga, más las dos
 * cadenas del widget de WiFi, que hasta ahora nunca se había llegado a pintar.
 * Sin fila en `pax_ui_i18n` el template cae al literal español y el huésped
 * extranjero lo ve suelto — ver el gotcha i18n de docs/Cotizaciones.md §6.
 *
 * `gui_acceso_pendiente_fecha` lleva `{{ fecha }}`: `maestroStore.t()` interpola
 * las variables con ese formato.
 *
 * Idempotente y no destructiva, igual que Version20260803140000.
 */
final class Version20260803235000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Semillas i18n de la guía del huésped (acceso, candado, errores, WiFi).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO pax_ui_i18n (id, scope, contenido, created_at, updated_at) VALUES
            ('gui_acceso_pendiente_fecha', 'guia', '[{"content": "Los códigos de acceso y el WiFi se muestran aquí a partir del {{ fecha }}.", "language": "es"}, {"content": "Access codes and WiFi will appear here from {{ fecha }}.", "language": "en"}, {"content": "Os códigos de acesso e o WiFi aparecem aqui a partir de {{ fecha }}.", "language": "pt"}, {"content": "Les codes d''accès et le WiFi s''afficheront ici à partir du {{ fecha }}.", "language": "fr"}, {"content": "I codici di accesso e il WiFi compariranno qui dal {{ fecha }}.", "language": "it"}, {"content": "Zugangscodes und WLAN erscheinen hier ab {{ fecha }}.", "language": "de"}, {"content": "Toegangscodes en wifi verschijnen hier vanaf {{ fecha }}.", "language": "nl"}]', NOW(), NOW()),
            ('gui_acceso_pendiente', 'guia', '[{"content": "Los códigos de acceso se mostrarán aquí poco antes de tu llegada.", "language": "es"}, {"content": "Access codes will appear here shortly before your arrival.", "language": "en"}, {"content": "Os códigos de acesso aparecerão aqui pouco antes da sua chegada.", "language": "pt"}, {"content": "Les codes d''accès s''afficheront ici peu avant votre arrivée.", "language": "fr"}, {"content": "I codici di accesso compariranno qui poco prima del tuo arrivo.", "language": "it"}, {"content": "Die Zugangscodes erscheinen hier kurz vor Ihrer Ankunft.", "language": "de"}, {"content": "De toegangscodes verschijnen hier kort voor je aankomst.", "language": "nl"}]', NOW(), NOW()),
            ('gui_acceso_no_confirmada', 'guia', '[{"content": "Tu reserva aún no está confirmada. Al confirmarse verás aquí las instrucciones de entrada.", "language": "es"}, {"content": "Your booking is not confirmed yet. Once it is, the entry instructions will appear here.", "language": "en"}, {"content": "A sua reserva ainda não está confirmada. Quando estiver, verá aqui as instruções de entrada.", "language": "pt"}, {"content": "Votre réservation n''est pas encore confirmée. Une fois confirmée, les instructions d''entrée s''afficheront ici.", "language": "fr"}, {"content": "La tua prenotazione non è ancora confermata. Una volta confermata, qui troverai le istruzioni di ingresso.", "language": "it"}, {"content": "Ihre Buchung ist noch nicht bestätigt. Sobald sie es ist, erscheinen hier die Zutrittshinweise.", "language": "de"}, {"content": "Je boeking is nog niet bevestigd. Zodra dat gebeurt, verschijnen hier de toegangsinstructies.", "language": "nl"}]', NOW(), NOW()),
            ('gui_acceso_expirada', 'guia', '[{"content": "Esta estancia ya terminó. La información de acceso ya no está disponible.", "language": "es"}, {"content": "This stay has ended. The access information is no longer available.", "language": "en"}, {"content": "Esta estadia terminou. As informações de acesso já não estão disponíveis.", "language": "pt"}, {"content": "Ce séjour est terminé. Les informations d''accès ne sont plus disponibles.", "language": "fr"}, {"content": "Questo soggiorno è terminato. Le informazioni di accesso non sono più disponibili.", "language": "it"}, {"content": "Dieser Aufenthalt ist beendet. Die Zugangsdaten sind nicht mehr verfügbar.", "language": "de"}, {"content": "Dit verblijf is afgelopen. De toegangsgegevens zijn niet meer beschikbaar.", "language": "nl"}]', NOW(), NOW()),
            ('gui_bloqueado', 'guia', '[{"content": "Aún no disponible", "language": "es"}, {"content": "Not available yet", "language": "en"}, {"content": "Ainda não disponível", "language": "pt"}, {"content": "Pas encore disponible", "language": "fr"}, {"content": "Non ancora disponibile", "language": "it"}, {"content": "Noch nicht verfügbar", "language": "de"}, {"content": "Nog niet beschikbaar", "language": "nl"}]', NOW(), NOW()),
            ('gui_error_titulo', 'guia', '[{"content": "Guía no disponible", "language": "es"}, {"content": "Guide not available", "language": "en"}, {"content": "Guia não disponível", "language": "pt"}, {"content": "Guide non disponible", "language": "fr"}, {"content": "Guida non disponibile", "language": "it"}, {"content": "Leitfaden nicht verfügbar", "language": "de"}, {"content": "Gids niet beschikbaar", "language": "nl"}]', NOW(), NOW()),
            ('gui_error_no_disponible', 'guia', '[{"content": "Este enlace ya no está activo o la estancia no tiene guía publicada.", "language": "es"}, {"content": "This link is no longer active, or the stay has no published guide.", "language": "en"}, {"content": "Este link já não está ativo ou a estadia não tem guia publicado.", "language": "pt"}, {"content": "Ce lien n''est plus actif ou ce séjour n''a pas de guide publié.", "language": "fr"}, {"content": "Questo link non è più attivo o il soggiorno non ha una guida pubblicata.", "language": "it"}, {"content": "Dieser Link ist nicht mehr aktiv oder für diesen Aufenthalt gibt es keinen Leitfaden.", "language": "de"}, {"content": "Deze link is niet meer actief of dit verblijf heeft geen gepubliceerde gids.", "language": "nl"}]', NOW(), NOW()),
            ('gui_error_throttle', 'guia', '[{"content": "Demasiados intentos. Vuelve a abrir el enlace en unos minutos.", "language": "es"}, {"content": "Too many attempts. Please open the link again in a few minutes.", "language": "en"}, {"content": "Demasiadas tentativas. Abra o link novamente daqui a alguns minutos.", "language": "pt"}, {"content": "Trop de tentatives. Rouvrez le lien dans quelques minutes.", "language": "fr"}, {"content": "Troppi tentativi. Riapri il link tra qualche minuto.", "language": "it"}, {"content": "Zu viele Versuche. Bitte öffnen Sie den Link in ein paar Minuten erneut.", "language": "de"}, {"content": "Te veel pogingen. Open de link over een paar minuten opnieuw.", "language": "nl"}]', NOW(), NOW()),
            ('gui_copiar', 'guia', '[{"content": "Copiar contraseña", "language": "es"}, {"content": "Copy password", "language": "en"}, {"content": "Copiar palavra-passe", "language": "pt"}, {"content": "Copier le mot de passe", "language": "fr"}, {"content": "Copia la password", "language": "it"}, {"content": "Passwort kopieren", "language": "de"}, {"content": "Wachtwoord kopiëren", "language": "nl"}]', NOW(), NOW()),
            ('gui_wifi_no_disponible', 'guia', '[{"content": "WiFi no disponible", "language": "es"}, {"content": "WiFi not available", "language": "en"}, {"content": "WiFi não disponível", "language": "pt"}, {"content": "WiFi non disponible", "language": "fr"}, {"content": "WiFi non disponibile", "language": "it"}, {"content": "WLAN nicht verfügbar", "language": "de"}, {"content": "Wifi niet beschikbaar", "language": "nl"}]', NOW(), NOW())
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
                'gui_acceso_pendiente_fecha', 'gui_acceso_pendiente', 'gui_acceso_no_confirmada',
                'gui_acceso_expirada', 'gui_bloqueado', 'gui_error_titulo',
                'gui_error_no_disponible', 'gui_error_throttle', 'gui_copiar', 'gui_wifi_no_disponible'
            )
        SQL);
    }
}
