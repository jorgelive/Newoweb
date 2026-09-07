<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La unidad de conteo llega a Operaciones: a la fila de La Biblia y al documento del proveedor.
 *
 * El rótulo de esa casilla en la reconciliación decía, literalmente, «Cantidad (noches/días)» — la
 * ambigüedad estaba admitida por escrito y sin resolver: la misma casilla decía 4 noches de hotel
 * y 5 días de seguro, y al proveedor le llegaba un número pelado.
 *
 * Se congela, como el tipo y los nombres: leer la unidad del catálogo al pintar haría que una
 * orden ya emitida se leyera distinta el día que alguien reclasifique el producto.
 *
 * ⚠️ Las filas existentes quedan en `unidades` (sin sustantivo), que es exactamente como se leían
 * hasta ahora: el número solo. Las rellena `app:operacion:backfill-unidades`.
 */
final class Version20260907152122 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Unidad de conteo y su sustantivo en la fila de La Biblia y en el ítem de la orden.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE operacion_servicio ADD unidad_de_conteo VARCHAR(20) DEFAULT 'unidades' NOT NULL, ADD sustantivo_unidad VARCHAR(30) DEFAULT NULL");
        $this->addSql('ALTER TABLE operacion_orden_servicio_item ADD sustantivo_unidad VARCHAR(30) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_orden_servicio_item DROP sustantivo_unidad');
        $this->addSql('ALTER TABLE operacion_servicio DROP unidad_de_conteo, DROP sustantivo_unidad');
    }
}
