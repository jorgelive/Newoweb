<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La plantilla `caja_dinero` pasa a llamarse `pago_efectivo_caja` (Jorge, 22/10/2026).
 *
 * «caja_dinero» era también el trozo común de sus variables (`codigo_caja_dinero`,
 * `foto_caja_dinero`, `video_caja_dinero`), y al pedirle al agente «mándale caja_dinero» o leerlo
 * en el panel no se distinguía la plantilla del dato. El nuevo nombre dice para qué es.
 *
 * Por SQL: `code` no lleva `#[AutoTranslate]` ni listeners. Las reglas y los mensajes apuntan a la
 * plantilla por id (0 y 0 en producción ese día), así que nada más cambia. La migración que la creó
 * (`Version20260913200000`) conserva el nombre viejo: es historia.
 */
final class Version20261022090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'msg_template: caja_dinero → pago_efectivo_caja';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE msg_template SET code = 'pago_efectivo_caja', name = 'Instrucciones pago en efectivo (caja)' WHERE code = 'caja_dinero'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE msg_template SET code = 'caja_dinero', name = 'Instrucciones caja del dinero' WHERE code = 'pago_efectivo_caja'");
    }
}
