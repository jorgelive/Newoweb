<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cuándo se confirmó la hora de llegada y la de salida de cada estancia.
 *
 * La hora sola no lo dice: una salida a las 10:00 es la de todos, la haya confirmado el huésped
 * o no haya dicho nada (Jorge, 01/10/2026). Ver `HoraDeLaEstancia::registrar()`.
 */
final class Version20261001220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'pms_evento_calendario: llegada_confirmada_at y salida_confirmada_at.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE pms_evento_calendario ADD llegada_confirmada_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', ADD salida_confirmada_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_evento_calendario DROP llegada_confirmada_at, DROP salida_confirmada_at');
    }
}
