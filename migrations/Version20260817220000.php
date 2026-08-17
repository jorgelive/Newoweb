<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Separa la hora de recojo de la hora vendida, y guarda el servicio del prestador.
 *
 * `hora_componente`: la hora tal como se vendió al cliente. `hora_recojo_real` era las dos
 * cosas a la vez, así que fijar el recojo pisaba la referencia. Ahora recojo es del operador
 * (no lo toca la reconciliación) y la vendida vive aparte.
 *
 * `prestador_servicio_nombre`: qué le provee el prestador —«habitación suite superior»—, lo
 * primero del voucher.
 *
 * ── Migración de datos ──────────────────────────────────────────────────────
 * `hora_componente` se rellena con lo que hoy tiene `hora_recojo_real`: es de donde salía. Así
 * las filas existentes conservan su hora vendida sin esperar a la reconciliación, y la de
 * recojo se queda como estaba (lo que el operador ya veía). El servicio del prestador lo pone
 * la reconciliación.
 */
final class Version20260817220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'operacion_servicio: hora_componente + prestador_servicio_nombre.';
    }

    public function up(Schema $schema): void
    {
        foreach (['hora_componente' => "VARCHAR(10) DEFAULT NULL", 'prestador_servicio_nombre' => "VARCHAR(255) DEFAULT NULL"] as $col => $tipo) {
            $existe = $this->connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
                ['t' => 'operacion_servicio', 'c' => $col]
            );
            if (!$existe) {
                $this->addSql(sprintf('ALTER TABLE operacion_servicio ADD %s %s', $col, $tipo));
            }
        }

        // La hora vendida sale de lo que hoy es la de recojo: es su origen.
        $this->addSql('UPDATE operacion_servicio SET hora_componente = hora_recojo_real WHERE hora_componente IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_servicio DROP hora_componente');
        $this->addSql('ALTER TABLE operacion_servicio DROP prestador_servicio_nombre');
    }
}
