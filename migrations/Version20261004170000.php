<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `msg_template.archivada`: la marca de archivo, que la sincronización con Meta no pisa.
 *
 * Se marcan las que ya estaban archivadas a la antigua —con todos los canales apagados— y que
 * ningún código usa. Las viejas que aún tienen canales se archivan después con
 * `msg:plantilla:archivar`, que pasa por la guarda de reglas activas.
 */
final class Version20261004170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'msg_template.archivada: marca de archivo';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE msg_template ADD archivada TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql("UPDATE msg_template SET archivada = 1 WHERE code IN ('welcome_airbnb', 'welcome_booking', 'recordatorio_llegada', 'mensaje_pendiente', 'solicitar_mensaje_whatsapp')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE msg_template DROP archivada');
    }
}
