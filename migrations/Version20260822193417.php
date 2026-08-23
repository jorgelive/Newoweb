<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El interruptor para enseñar los puntos de una línea que está en medio de una cadena.
 *
 * Escrita a mano, como las anteriores: el `diff` quiere además borrar las tablas `energia_*` y un
 * backup que ya no tienen entidad, y eso se retira en su propia migración mirando qué hay dentro.
 */
final class Version20260822193417 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Puntos siempre visibles: interruptor en La Biblia y su copia congelada en el ítem.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_servicio ADD puntos_siempre_visibles TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE operacion_orden_servicio_item ADD puntos_siempre_visibles TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_orden_servicio_item DROP puntos_siempre_visibles');
        $this->addSql('ALTER TABLE operacion_servicio DROP puntos_siempre_visibles');
    }
}
