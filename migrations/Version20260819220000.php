<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `cotizacion_cottarifa`: congela el prestador y el comprador de la tarifa.
 *
 * La cottarifa ya congelaba doce campos de la tarifa maestra —título, modalidad, categoría,
 * edades, capacidades…— porque una cotización es un documento HISTÓRICO: lo prometido no puede
 * cambiar porque alguien edite el catálogo después. Faltaban los dos papeles.
 *
 * De aquí suben al `CotizacionCotcomponente` con la regla de `PapelDeLaTarifa`: **el primero
 * manda, el distinto avisa y pasa**. Y de ahí los propaga `BibliaSnapshotService` hasta la orden
 * de servicio, que es donde acaban importando: si hay comprador, la orden sale a su nombre.
 *
 * Nacen vacías. Las cotizaciones ya existentes conservan el prestador que tengan puesto a mano
 * en el componente; nadie deduce hacia atrás.
 */
final class Version20260819220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'cotizacion_cottarifa: congela prestador y comprador (soft-link + nombre histórico).';
    }

    public function up(Schema $schema): void
    {
        if ($this->columnaExiste('prestador_maestro_id')) {
            $this->write('  · cotizacion_cottarifa.prestador_maestro_id ya existe; se omite.');

            return;
        }

        $this->addSql(<<<'SQL'
            ALTER TABLE cotizacion_cottarifa
                ADD prestador_maestro_id VARCHAR(36) DEFAULT NULL,
                ADD prestador_nombre_snapshot VARCHAR(190) DEFAULT NULL,
                ADD comprador_maestro_id VARCHAR(36) DEFAULT NULL,
                ADD comprador_nombre_snapshot VARCHAR(190) DEFAULT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        if (!$this->columnaExiste('prestador_maestro_id')) {
            return;
        }

        $this->addSql(<<<'SQL'
            ALTER TABLE cotizacion_cottarifa
                DROP prestador_maestro_id,
                DROP prestador_nombre_snapshot,
                DROP comprador_maestro_id,
                DROP comprador_nombre_snapshot
            SQL);
    }

    private function columnaExiste(string $columna): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['cotizacion_cottarifa', $columna]
        ) > 0;
    }
}
