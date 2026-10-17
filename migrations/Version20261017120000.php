<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * «Two Day Camino inca» decía «Duración: 1 día» y tiene dos: la caminata del km 104 el primero y
 * Machu Picchu el segundo. Era la única plantilla con la duración distinta de sus días (medido el
 * 17/10/2026 comparando `duracion_dias` con el `MAX(dia)` de sus segmentos).
 *
 * Por migración y no por comando: el campo es un número que ningún listener recalcula ni traduce.
 * Acotada por nombre Y por el valor viejo, así que en una base donde ya esté bien no toca nada.
 */
final class Version20261017120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Two Day Camino inca: duración 2 días (decía 1)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE travel_itinerario SET duracion_dias = 2 WHERE nombre_interno = 'Two Day Camino inca' AND duracion_dias = 1");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE travel_itinerario SET duracion_dias = 1 WHERE nombre_interno = 'Two Day Camino inca' AND duracion_dias = 2");
    }
}
