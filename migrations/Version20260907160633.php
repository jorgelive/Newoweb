<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cuándo TERMINA lo que dura, en la fila de La Biblia y en el documento del proveedor.
 *
 * Faltaba, y se notaba donde más duele: el mensaje que recibe el hotelero decía «Lun 31 ago ·
 * Habitación Superior · 2 pax» — la entrada, sin salida y sin número de noches. El encargo sin su
 * duración, que además es la parte que más se pregunta por teléfono.
 *
 * ⚠️ Nulo no significa «acaba el mismo día»: significa «no se ha rellenado». Lo rellena
 * `app:operacion:backfill-unidades`, que lo lee del componente de la cotización.
 */
final class Version20260907160633 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fecha de fin en la fila de La Biblia y en el ítem congelado de la orden.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_servicio ADD fecha_fin_servicio DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\'');
        $this->addSql('ALTER TABLE operacion_orden_servicio_item ADD fecha_fin DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_orden_servicio_item DROP fecha_fin');
        $this->addSql('ALTER TABLE operacion_servicio DROP fecha_fin_servicio');
    }
}
