<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Dónde se escribieron los mensajes del alojamiento que llegaron sincronizados por Beds24 —la app
 * o la extranet de la plataforma—: `metadata.escrito_en` con el nombre del canal de la reserva. Lo
 * pone desde hoy `Beds24ReceivePersister`; aquí se rellena lo anterior, para que el chat diga
 * «Escrito en Booking.com» en vez de que el núcleo traduzca `booking`.
 *
 * La reserva sale del asunto estampado en el mensaje o, si no lo lleva, de la cabecera de su
 * conversación. Sólo datos: ningún listener recalcula esta clave.
 *
 * ⚠️ 1.502 de esos mensajes guardan la metadata como el ARRAY vacío `[]`, no como objeto, y
 * `JSON_SET('$.escrito_en')` sobre un array no hace nada y no avisa: la primera versión rellenó
 * 287 de 1.789. El `[]` vacío se cambia por un objeto; uno que tuviera algo dentro no se toca.
 */
final class Version20261003230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'metadata.escrito_en en los mensajes del alojamiento sincronizados por Beds24';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE msg_message m
            JOIN msg_conversation c ON c.id = m.conversation_id
            JOIN pms_reserva r ON r.id = UNHEX(REPLACE(COALESCE(
                    IF(m.asunto_type = 'pms_reserva', m.asunto_id, NULL),
                    IF(c.context_type = 'pms_reserva', c.context_id, NULL)
                ), '-', ''))
            JOIN pms_channel ch ON ch.id = r.channel_id
            SET m.metadata = JSON_SET(
                    IF(m.metadata IS NULL OR (JSON_TYPE(m.metadata) = 'ARRAY' AND JSON_LENGTH(m.metadata) = 0), JSON_OBJECT(), m.metadata),
                    '$.escrito_en', ch.nombre)
            WHERE m.direction = 'outgoing'
              AND m.sender_type = 'host'
              AND m.channel_id = 'beds24'
              AND ch.es_directo = 0
              AND JSON_EXTRACT(m.metadata, '$.escrito_en') IS NULL
              AND (m.metadata IS NULL OR JSON_TYPE(m.metadata) = 'OBJECT' OR JSON_LENGTH(m.metadata) = 0)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE msg_message SET metadata = JSON_REMOVE(metadata, '$.escrito_en') WHERE JSON_EXTRACT(metadata, '$.escrito_en') IS NOT NULL");
    }
}
