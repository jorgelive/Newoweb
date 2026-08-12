<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El canal de correo se apaga: prometía algo que no existe.
 *
 * `msg_channel.email` estaba **activo**, y a la vez las **doce** plantillas tenían su
 * `email_tmpl.is_active` en `false`. `MessageDispatcher::resolveChannels()` sólo ofrece un canal
 * si la plantilla lo permite, así que por correo no ha salido nunca nada — pero el canal aparecía
 * disponible en el panel, con su casilla, invitando a marcarlo.
 *
 * Marcarlo no habría enviado un correo: habría sumado un canal que ninguna plantilla autoriza y,
 * si era el único marcado, el mensaje se habría quedado en `FAILED` con «no se pudo generar
 * ninguna cola». Un canal encendido que no puede entregar es peor que uno apagado.
 *
 * ── Lo que NO se toca ───────────────────────────────────────────────────────
 * El contenido. `welcome_booking` tiene su cuerpo de correo escrito en los siete idiomas —trabajo
 * hecho que no se ha enviado nunca— y se queda donde está. Apagar el canal no borra nada: el día
 * que se quiera correo de verdad, se vuelve a encender aquí y se activa `email_tmpl.is_active` en
 * las plantillas que toque.
 *
 * Reversible en una línea, que es justo por lo que va en una migración y no a mano.
 */
final class Version20260812220000 extends AbstractMigration
{
    private const string CANAL = 'email';

    public function getDescription(): string
    {
        return 'Apaga el canal de correo: ninguna plantilla lo autoriza, así que no puede entregar nada';
    }

    public function up(Schema $schema): void
    {
        $this->cambiar(false);
    }

    public function down(Schema $schema): void
    {
        $this->cambiar(true);
    }

    private function cambiar(bool $activo): void
    {
        $filas = $this->connection->executeStatement(
            'UPDATE msg_channel SET is_active = :activo WHERE id = :id',
            ['activo' => $activo ? 1 : 0, 'id' => self::CANAL]
        );

        $this->write(
            $filas === 0
                ? sprintf('  ⚠️ No existe el canal «%s»; no se toca nada.', self::CANAL)
                : sprintf('  Canal «%s» %s.', self::CANAL, $activo ? 'encendido' : 'apagado')
        );
    }
}
