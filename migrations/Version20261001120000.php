<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El link sabe qué es: `pms_evento_beds24_link.rol` y un único `(evento, mapa, rol)`.
 *
 * Fase 1 de docs/PlanHorarioExtraSinEventos.md. Todos los links que hay son de la estancia
 * (principal o espejo), así que el `DEFAULT` los deja bien sin `UPDATE` aparte. Los roles
 * `extra_entrada` / `extra_salida` llegan en la fase 2.
 *
 * El único es NUEVO, no un cambio: el `(evento_id, unidad_beds24_map_id)` que declaraba la
 * entidad dentro de `#[ORM\Table(uniqueConstraints: …)]` nunca llegó a la base (este Doctrine
 * ignora esos argumentos anidados). Comprobado antes de crearlo: 0 pares repetidos el 01/10/2026.
 */
final class Version20261001120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rol del link de Beds24 (estancia / extra_entrada / extra_salida) y único (evento, mapa, rol).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE pms_evento_beds24_link ADD rol VARCHAR(20) DEFAULT 'estancia' NOT NULL");
        $this->addSql('CREATE UNIQUE INDEX uniq_link_evento_mapa_rol ON pms_evento_beds24_link (evento_id, unidad_beds24_map_id, rol)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_link_evento_mapa_rol ON pms_evento_beds24_link');
        $this->addSql('ALTER TABLE pms_evento_beds24_link DROP rol');
    }
}
