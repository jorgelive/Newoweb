<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260807161847 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'agente_habilitada pasa a autoenvio_habilitada: ya no acota lo que el agente '
            . 'puede mandar (puede mandar todo lo que tenga uso escrito, con la correspondencia '
            . 'de OTA como guardia), sino lo que el huésped puede pedirse a sí mismo.';
    }

    public function up(Schema $schema): void
    {
        // CHANGE y no ADD+DROP: los valores actuales se conservan a propósito. Las plantillas
        // que el panel había habilitado para el agente (guía, menú de tours) son exactamente
        // las candidatas naturales al autoenvío; revisar la lista en el panel tras migrar.
        $this->addSql('ALTER TABLE msg_template CHANGE agente_habilitada autoenvio_habilitada TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE msg_template CHANGE autoenvio_habilitada agente_habilitada TINYINT(1) DEFAULT 0 NOT NULL');
    }
}
