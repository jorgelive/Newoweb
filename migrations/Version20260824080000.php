<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Un párrafo SIN maestro puede declarar dónde empieza y dónde acaba.
 *
 * Los puntos salían del `TravelSegmento` del catálogo, así que un párrafo escrito a mano —el
 * traslado a «La Olla de Juanita», que no está en ningún catálogo ni va a estarlo— se quedaba en
 * «sin definir» para siempre. El único sitio donde declararlo era el override de la orden ya
 * emitida: dos capas más abajo y sólo después de confirmar.
 *
 * ⚠️ Con maestro estos campos ni se leen. No es un override — ver `CotizacionPuntosDelServicio`.
 */
final class Version20260824080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'cotizacion_segmento: inicio_texto y fin_texto para los párrafos sin maestro.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_segmento ADD inicio_texto VARCHAR(180) DEFAULT NULL, ADD fin_texto VARCHAR(180) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_segmento DROP inicio_texto, DROP fin_texto');
    }
}
