<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La fusión sugerida: cuando un teléfono o un correo llega por el dominio y ya es de otro hilo,
 * se apunta en el hilo y se avisa al equipo, en vez de dejar sólo una línea en el log.
 *
 * Nulables las dos: «no hay sugerencia» y «no se descartó nada» son el estado de casi todos los
 * hilos, y una `JSON NOT NULL` añadida a una tabla con filas se rellena con el literal `null`
 * (ver CLAUDE.md). Ver `docs/Mensajeria.md`, «El choque de identificadores que nadie veía».
 */
final class Version20261002050259 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'msg_conversation: fusion_sugerida y fusiones_descartadas.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE msg_conversation ADD fusion_sugerida JSON DEFAULT NULL, ADD fusiones_descartadas JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE msg_conversation DROP fusion_sugerida, DROP fusiones_descartadas');
    }
}
