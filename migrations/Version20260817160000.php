<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `operacion_servicio.cantidad_componente`: las noches/días/tramos del componente.
 *
 * La fila decía «Hotel 4 estrellas» sin las noches, que es la mitad de lo que se le encarga
 * al hotel. Es el snapshot de `CotizacionCotcomponente::$cantidad`, y ya se multiplica en el
 * costo (§3.9) — sólo faltaba guardarlo aparte para poder MOSTRARLO.
 *
 * Nace en 1 para todas las filas existentes; el valor real lo pone la reconciliación
 * (`operacion:resincronizar`), como cualquier otro campo gobernado por la cotización.
 */
final class Version20260817160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'operacion_servicio: añade cantidad_componente (noches/días/tramos).';
    }

    public function up(Schema $schema): void
    {
        $existe = $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'operacion_servicio'
              AND COLUMN_NAME = 'cantidad_componente'
        SQL);

        if (!$existe) {
            $this->addSql('ALTER TABLE operacion_servicio ADD cantidad_componente INT DEFAULT 1 NOT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_servicio DROP cantidad_componente');
    }
}
