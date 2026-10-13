<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `cotizacion_catalogo.ofrecido_por_agente`: el agente del chat ofrece el catálogo aunque no esté
 * en la web. Ver `CotizacionCatalogo::loOfreceElAgente()` y docs/Agent.md §5.8.
 *
 * Nace en 0 en todas las filas, y es lo correcto: sólo SUMA a lo publicado en la web, que el
 * agente ya ofrece. Ningún catálogo cambia de visibilidad al migrar.
 */
final class Version20261013090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'cotizacion_catalogo.ofrecido_por_agente (el agente lo ofrece aunque no esté en la web)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_catalogo ADD ofrecido_por_agente TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_catalogo DROP ofrecido_por_agente');
    }
}
