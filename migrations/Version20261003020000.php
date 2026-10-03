<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Los índices que estaban declarados dentro de `#[ORM\Table(indexes: …)]` y nunca llegaron a la
 * base: este Doctrine ignora esos argumentos sin avisar. Pasan a atributos de clase, y sólo los
 * que alguna consulta usa.
 *
 * - Las dos colas que no tenían el índice de worker `(status, run_at)` que tienen las demás: el
 *   sondeo `FOR UPDATE SKIP LOCKED` de `claimRunnable` recorría todas sus filas (57 608 y 15 504).
 * - Las auditorías de webhooks, por `received_at`: el orden de su listado en el panel.
 * - El único (unidad, listing) del mapa de Beds24, que respalda a su `UniqueEntity`. Sin
 *   duplicados en producción al crearlo (03/10/2026).
 */
final class Version20261003020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Índices declarados anidados en ORM\Table que nunca se crearon';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_rpq_worker ON pms_rates_push_queue (status, run_at)');
        $this->addSql('CREATE INDEX idx_pms_pull_queue_worker ON pms_bookings_pull_queue (status, run_at)');
        $this->addSql('CREATE INDEX idx_beds24_wh_received_at ON pms_beds24_webhook_audit (received_at)');
        $this->addSql('CREATE INDEX idx_meta_wh_received_at ON msg_meta_webhook_audit (received_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_unidad_virtual ON pms_unidad_beds24_map (pms_unidad_id, virtual_establecimiento_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_rpq_worker ON pms_rates_push_queue');
        $this->addSql('DROP INDEX idx_pms_pull_queue_worker ON pms_bookings_pull_queue');
        $this->addSql('DROP INDEX idx_beds24_wh_received_at ON pms_beds24_webhook_audit');
        $this->addSql('DROP INDEX idx_meta_wh_received_at ON msg_meta_webhook_audit');
        $this->addSql('DROP INDEX uniq_unidad_virtual ON pms_unidad_beds24_map');
    }
}
