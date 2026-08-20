<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El papel de TITULAR en el enlace: qué hilo atiende un asunto.
 *
 * Un asunto puede colgar de VARIOS hilos y el esquema ya lo permitía —el único índice es
 * `(conversacion_id, reserva_id)`—. El caso real es una reserva con dos huéspedes que escriben
 * desde números distintos: dos personas, dos hilos, y fundirlos sería mentir.
 *
 * Lo que no puede duplicarse es la AGENDA: con dos agendas vivas de la misma reserva, la
 * bienvenida, el recordatorio de saldo y el check-out salen dos veces — y el aviso de pago no
 * es para el acompañante. Así que la respuesta no fue prohibir el segundo enlace, sino decir
 * cuál manda.
 *
 * Y de paso es el camino DURADERO de resolución: `asunto → enlace titular → hilo`, que no
 * depende de ningún identificador de persona. Un `bookId` de Beds24 es la dirección de la
 * ESTANCIA, no de nadie: hay 46 repartidos en 38 hilos y una misma persona acumula siete.
 */
final class Version20260820340000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade es_titular a los enlaces de conversación y degrada el enlace huérfano de la fusión.';
    }

    public function up(Schema $schema): void
    {
        // Por defecto TITULAR: el caso normal es un asunto con un solo hilo, y el valor seguro
        // es que la agenda salga. Nacer en 0 dejaría reservas sin bienvenida ni recordatorios,
        // y eso no lo delata nada: nadie echa de menos un mensaje que no sabía que existía.
        $this->addSql('ALTER TABLE pms_conversacion_enlace ADD es_titular TINYINT(1) DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE cotizacion_conversacion_enlace ADD es_titular TINYINT(1) DEFAULT 1 NOT NULL');

        // ── El único caso real de asunto en dos hilos, y no es legítimo ─────────
        // Es un resto de `app:message:fusionar-hilos`: los enlaces se mueven con `UPDATE
        // IGNORE`, y cuando el hilo superviviente YA tenía enlace para esa reserva, el del
        // absorbido se quedó donde estaba. Está archivado y con 0 mensajes, así que no molesta
        // — pero es una agenda duplicada esperando.
        //
        // No se borra, se degrada: el enlace sigue contando que ese hilo trató esa reserva.
        $this->addSql(<<<'SQL'
            UPDATE pms_conversacion_enlace e
              JOIN msg_conversation c ON c.id = e.conversacion_id
               SET e.es_titular = 0
             WHERE JSON_EXTRACT(c.context_data, '$.fusionado_en') IS NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_conversacion_enlace DROP es_titular');
        $this->addSql('ALTER TABLE cotizacion_conversacion_enlace DROP es_titular');
    }
}
