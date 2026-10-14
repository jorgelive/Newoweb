<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `msg_identidad.ventana_whatsapp_hasta`: la ventana de 24 h de WhatsApp, por NÚMERO.
 * Ver `MessageIdentidad::$ventanaWhatsappHasta` y docs/Mensajeria.md.
 *
 * ⚠️ El relleno es una APROXIMACIÓN y es deliberada: hasta hoy la ventana era del hilo y no se
 * sabía qué número la abrió. Se le da al número de salida (`guest_phone`), que es a quien se
 * escribía con ella. Una ventana dura 24 h, así que la imprecisión se extingue sola en un día.
 */
final class Version20261014090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'msg_identidad.ventana_whatsapp_hasta (ventana de WhatsApp por número) + relleno desde el hilo';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE msg_identidad ADD ventana_whatsapp_hasta DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql(
            'UPDATE msg_identidad i
               JOIN msg_conversation c ON c.id = i.conversacion_id
                SET i.ventana_whatsapp_hasta = c.whatsapp_session_valid_until
              WHERE i.tipo = \'telefono\'
                AND i.valor = c.guest_phone
                AND c.whatsapp_session_valid_until IS NOT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE msg_identidad DROP ventana_whatsapp_hasta');
    }
}
