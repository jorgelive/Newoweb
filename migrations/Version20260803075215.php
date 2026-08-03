<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La Biblia: snapshot de la clasificación del componente en OperacionServicio.
 *
 * Sin estos campos la fila operativa sólo conocía el nombre interno de la tarifa, que no
 * permite distinguir un traslado de un desayuno ni ordenar por prioridad de despacho.
 */
final class Version20260803075215 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade contexto y clasificación del componente (tipo/modo/estado) a operacion_servicio.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_servicio ADD contexto_servicio VARCHAR(255) DEFAULT NULL, ADD tipo_componente VARCHAR(30) DEFAULT NULL, ADD modo_componente VARCHAR(30) DEFAULT NULL, ADD estado_componente VARCHAR(30) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_ops_servicio_tipo ON operacion_servicio (tipo_componente)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_ops_servicio_tipo ON operacion_servicio');
        $this->addSql('ALTER TABLE operacion_servicio DROP contexto_servicio, DROP tipo_componente, DROP modo_componente, DROP estado_componente');
    }
}
