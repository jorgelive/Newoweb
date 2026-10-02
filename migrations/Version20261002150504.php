<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El huésped que prefiere no dejar su WhatsApp: cuándo lo dijo, para no volver a pedírselo.
 *
 * En la reserva y no en la conversación: lo dijo en la página de ESA reserva, y la página es lo
 * que deja de preguntar. Ver `docs/Mensajeria.md`, «Pedirle el teléfono al huésped».
 */
final class Version20261002150504 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'pms_reserva.telefono_rechazado_at: el huésped prefirió no dejar su WhatsApp.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE pms_reserva ADD telefono_rechazado_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_reserva DROP telefono_rechazado_at');
    }
}
