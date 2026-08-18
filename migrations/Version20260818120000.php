<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `pms_evento_calendario.estado_push_solicitado`: intención de empujar el estado a Beds24.
 *
 * Rompe el huevo-y-la-gallina del push de OTA. El `status` de una reserva de canal ahora sólo
 * viaja cuando este flag está puesto —el operador cambió el estado a propósito—, no en cada
 * re-push del cron comparando contra un `estadoBeds24` que un pull atrasado dejaba caduco.
 * Lo pone el listener de cola al detectar un cambio LOCAL de estado; lo limpia el handler del
 * push. Ver docs/PmsBeds24ReservasSync.md.
 *
 * Nace en `false` para todas las filas: nadie ha pedido un override todavía.
 */
final class Version20260818120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'pms_evento_calendario: añade estado_push_solicitado (intención de push de estado OTA).';
    }

    public function up(Schema $schema): void
    {
        $existe = $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'pms_evento_calendario' AND COLUMN_NAME = 'estado_push_solicitado'
        SQL);

        if (!$existe) {
            $this->addSql('ALTER TABLE pms_evento_calendario ADD estado_push_solicitado TINYINT(1) DEFAULT 0 NOT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_evento_calendario DROP estado_push_solicitado');
    }
}
