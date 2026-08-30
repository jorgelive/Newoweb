<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `orden_itinerario` en la línea de la orden: desempata las que no tienen hora.
 *
 * Congelado al emitir, como el resto de la línea: una orden se lee igual dentro de un año aunque
 * el itinerario se haya reordenado. Nulo en las emitidas antes, que desempatan como entonces.
 */
final class Version20260829234500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'operacion_orden_servicio_item.orden_itinerario: desempate de las líneas sin hora';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_orden_servicio_item ADD orden_itinerario INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_orden_servicio_item DROP orden_itinerario');
    }
}
