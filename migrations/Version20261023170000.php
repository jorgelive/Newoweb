<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `travel_servicio.contenedor_generico`: los repertorios, que el cliente no ve como servicio.
 *
 * Ver `TravelServicio::$contenedorGenerico`. Se marcan los servicios que agrupan piezas sueltas
 * (Jorge, 23/10/2026): los transportes de cada ciudad, vuelos, alojamiento, lo del aeropuerto de
 * Lima, las actividades de resort, los extras y el seguro. Se quedan SIN marca los productos que
 * no tienen plantilla —Camino Inca, Coco Bongo, Isla Saona— y todo lo que tiene plantilla.
 */
final class Version20261023170000 extends AbstractMigration
{
    private const array CODIGOS = [
        'TRF_CUZ', 'TRF_LIM', 'TRF_PUN', 'TRF_PUJ', 'VUELO', 'ALO', 'VAR_APT_LIM', 'ACT_RESORT', 'EXTRAS', 'SEGURO',
    ];

    public function getDescription(): string
    {
        return 'travel_servicio.contenedor_generico, marcado en los repertorios';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE travel_servicio ADD contenedor_generico TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql(
            'UPDATE travel_servicio SET contenedor_generico = 1 WHERE codigo IN (' . implode(', ', array_fill(0, \count(self::CODIGOS), '?')) . ')',
            self::CODIGOS
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE travel_servicio DROP contenedor_generico');
    }
}
