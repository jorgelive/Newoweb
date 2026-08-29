<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El tipo del componente viaja con la línea de la orden.
 *
 * Decide cuál de los dos nombres —componente o segmento— va en grande para el proveedor
 * (`ComponenteTipoEnum::mandaElSegmento()`). Se congela con el resto: leerlo del maestro al
 * pintar haría que una orden emitida se leyera distinta el día que el catálogo cambie.
 *
 * Sólo la columna. El relleno de las emitidas va por comando, que decide fila a fila.
 */
final class Version20260829180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'operacion_orden_servicio_item.tipo_componente: decide qué nombre manda en la orden';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_orden_servicio_item ADD tipo_componente VARCHAR(30) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_orden_servicio_item DROP tipo_componente');
    }
}
