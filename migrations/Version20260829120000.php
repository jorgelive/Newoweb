<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El nombre del segmento viaja con la línea de la orden.
 *
 * Se calculaba en La Biblia desde siempre (`operacion_servicio.nombre_segmento`) y no se copiaba
 * a la orden emitida, así que al proveedor no le llegaba nunca. Por eso el nombre del COMPONENTE
 * tenía que cargar con la ruta —«Transporte Aeropuerto Lima - Hotel Lima»—, y de ahí salió un
 * componente por destino con su juego de tarifas repetido.
 *
 * Sólo la columna: el relleno de las órdenes ya emitidas va por comando
 * (`app:operacion:rellenar-nombre-segmento`), porque lee el snapshot de La Biblia y decide por
 * fila. Un UPDATE a ciegas aquí no distingue una orden anulada de una viva.
 */
final class Version20260829120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'operacion_orden_servicio_item.nombre_segmento: el momento del programa en la orden del proveedor';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_orden_servicio_item ADD nombre_segmento VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_orden_servicio_item DROP nombre_segmento');
    }
}
