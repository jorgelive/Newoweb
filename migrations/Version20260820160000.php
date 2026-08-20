<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `CotizacionCottarifa` congela también el SERVICIO del prestador.
 *
 * Ya guardaba el prestador y el comprador; faltaba el tercero, que llegó a `TravelTarifa` con
 * {@see Version20260820140000}. Se congela por lo mismo que el resto del snapshot: una cotización
 * es un documento **histórico**, y lo que se le prometió al cliente no puede cambiar porque
 * alguien edite el maestro después.
 *
 * Columnas y no relación, igual que sus hermanas: es un **soft-link**. Si la organización o su
 * servicio desaparecen del catálogo, la cotización sigue contando qué se contrató —el uuid y el
 * nombre que quedaron escritos—, que es lo único que hace falta de un proveedor que ya no
 * trabaja contigo.
 *
 * Nacen vacías. Se llenan al elegir la tarifa, no hacia atrás: rellenar cotizaciones viejas con
 * el catálogo de hoy es justo lo que el snapshot existe para impedir.
 */
final class Version20260820160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CotizacionCottarifa: congela el servicio del prestador junto al prestador y el comprador.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_cottarifa
            ADD prestador_servicio_maestro_id VARCHAR(36) DEFAULT NULL,
            ADD prestador_servicio_nombre_snapshot VARCHAR(190) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_cottarifa
            DROP prestador_servicio_maestro_id,
            DROP prestador_servicio_nombre_snapshot');
    }
}
