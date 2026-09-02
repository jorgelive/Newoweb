<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `version` → `propuesta` en cotizacion_cotizacion.
 *
 * No son versiones: las propuestas NO se sustituyen entre sí. Un expediente puede tener varias
 * vivas a la vez y el cliente puede aprobar más de una, porque a veces son complementarias —una
 * la parte de Lima del itinerario y otra la de Bolivia—. El nombre hacía razonar mal a todo el
 * que lo leía. Ver `docs/Cotizaciones.md` §6.j.0.
 *
 * ⚠️ **Sólo metadatos: `RENAME COLUMN` en MySQL 8 no reescribe filas**, así que el coste no
 * depende del volumen. Se hace ahora y no «cuando toque» porque se comprobó que el trabajo que
 * viene —la propuesta operativa— NO toca esta tabla: espera una ocasión que no iba a llegar,
 * mientras cada función nueva sumaba un sitio más leyendo el nombre equivocado.
 *
 * Los dos índices se recrean con el nombre nuevo: dejarlos con el viejo sería la misma mentira
 * un piso más abajo.
 */
final class Version20260902221114 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Renombra cotizacion_cotizacion.version a propuesta (y sus dos índices).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_cotizacion_catalogo_version ON cotizacion_cotizacion');
        $this->addSql('DROP INDEX idx_cotizacion_file_version ON cotizacion_cotizacion');
        $this->addSql('ALTER TABLE cotizacion_cotizacion CHANGE version propuesta INT NOT NULL');
        $this->addSql('CREATE INDEX idx_cotizacion_file_propuesta ON cotizacion_cotizacion (file_id, propuesta)');
        $this->addSql('CREATE INDEX idx_cotizacion_catalogo_propuesta ON cotizacion_cotizacion (catalogo_id, propuesta)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_cotizacion_catalogo_propuesta ON cotizacion_cotizacion');
        $this->addSql('DROP INDEX idx_cotizacion_file_propuesta ON cotizacion_cotizacion');
        $this->addSql('ALTER TABLE cotizacion_cotizacion CHANGE propuesta version INT NOT NULL');
        $this->addSql('CREATE INDEX idx_cotizacion_file_version ON cotizacion_cotizacion (file_id, version)');
        $this->addSql('CREATE INDEX idx_cotizacion_catalogo_version ON cotizacion_cotizacion (catalogo_id, version)');
    }
}
