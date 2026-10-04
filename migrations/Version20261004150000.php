<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `msg_template.envio_manual`: si la plantilla se ofrece en los menús para mandarla a mano.
 *
 * Fuera, las que sólo dispara el sistema y las generaciones viejas que ninguna regla usa ya
 * (Jorge, 04/10/2026). No cambia ningún envío automático: sólo lo que se ofrece a mano, y se
 * vuelve a marcar desde el panel.
 */
final class Version20261004150000 extends AbstractMigration
{
    /** Las manda sólo el sistema (respuestas a botones, avisos automáticos). */
    private const array DEL_SISTEMA = [
        'pedir_hora_llegada_tarde', 'pedir_hora_llegada_antes', 'pedir_hora_salida_tarde',
        'pedir_hora_salida_antes', 'salida_confirmada', 'mensaje_pendiente', 'respuesta_pendiente',
        'pago_recibido',
    ];

    /** Generaciones anteriores, sin regla que las use: las sustituyen las «con botones». */
    private const array VIEJAS = [
        'aviso_salida', 'welcome_airbnb', 'welcome_booking', 'recordatorio_llegada', 'guia_llegada',
        'guia_llegada_booking',
    ];

    public function getDescription(): string
    {
        return 'msg_template.envio_manual: qué plantillas se ofrecen para mandar a mano';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE msg_template ADD envio_manual TINYINT(1) DEFAULT 1 NOT NULL');

        $codigos = implode(', ', array_map(static fn (string $c): string => "'" . $c . "'", [...self::DEL_SISTEMA, ...self::VIEJAS]));
        $this->addSql("UPDATE msg_template SET envio_manual = 0 WHERE code IN ($codigos)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE msg_template DROP envio_manual');
    }
}
