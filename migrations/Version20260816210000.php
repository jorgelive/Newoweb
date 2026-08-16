<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La Biblia recibe el COMPRADOR: a quién se le manda el encargo de comprar.
 *
 * Espejo en Operación de las columnas que `Version20260816200000` añadió a la cotización.
 * Siempre apunta a un `travel_proveedor`, también cuando el comprador es una parte interna
 * de la empresa: ahí también está modelada como proveedor.
 *
 * Adición pura y sin backfill: el dato no existía. Mientras las columnas estén vacías,
 * `resolverComprador()` cae al proveedor, así que **la Orden de Servicio se sigue dirigiendo
 * exactamente a quien se dirigía hasta ahora**.
 */
final class Version20260816210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'OperacionServicio: snapshot del comprador.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_servicio
            ADD comprador_maestro_id VARCHAR(36) DEFAULT NULL,
            ADD comprador_nombre VARCHAR(150) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_servicio
            DROP comprador_maestro_id,
            DROP comprador_nombre');
    }
}
