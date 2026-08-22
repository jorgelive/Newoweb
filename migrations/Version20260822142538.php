<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Punto de recojo y de entrega: el override del operador y su copia congelada.
 *
 * Escrita a mano y no con `diff`, como la de los puntos de segmento: el diff quiere además
 * borrar las tablas `energia_*` y un backup, que ya no tienen entidad. Eso, si sobra, se retira
 * en su propia migración y mirando qué hay dentro.
 */
final class Version20260822142538 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Punto de recojo/entrega en La Biblia (override) y su congelado en el ítem de la orden.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_servicio ADD punto_recojo VARCHAR(255) DEFAULT NULL, ADD punto_entrega VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE operacion_orden_servicio_item ADD punto_recojo_confirmado VARCHAR(255) DEFAULT NULL, ADD punto_entrega_confirmado VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_orden_servicio_item DROP punto_recojo_confirmado, DROP punto_entrega_confirmado');
        $this->addSql('ALTER TABLE operacion_servicio DROP punto_recojo, DROP punto_entrega');
    }
}
