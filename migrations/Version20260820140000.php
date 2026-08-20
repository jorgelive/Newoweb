<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `TravelTarifa` recupera los tres roles, ahora con su nombre propio.
 *
 * El par proveedor/proveedorServicio vivió aquí del 13/06 al 16/08/2026, cuando
 * {@see Version20260816240000} lo subió a `TravelComponente` porque estaba casi vacío —5 de 904,
 * y `proveedor_servicio_id` en 0 de 904—. {@see Version20260820120000} lo quitó también de allí,
 * al ver que un componente **puede tener tarifas de empresas distintas**.
 *
 * Ésta es la conclusión de esas dos vueltas: **la línea de precio es el sitio correcto**, porque
 * es la única granularidad en la que la afirmación es cierta. «Tren Ollanta – Machu Picchu» se
 * compra a PeruRail y a IncaRail: el componente no tiene un prestador, cada tarifa sí.
 *
 * Y llega uno nuevo, `comprador`, que es el que da el motivo que faltaba para llenarlos: la Orden
 * de Servicio sale a nombre de **quien recibe el encargo**, no de quien presta. Vacío = se le
 * compra al prestador, que es el caso normal.
 *
 * ⚠️ **Las columnas nacen vacías, incluidas las 5 filas viejas.** El dato de entonces se recuperó
 * del volcado `openperu_v2-pre-roles-20260816-2118.sql.gz` y era: las cuatro tarifas de «Pool Lima
 * City» → Futurismo Jonathan, y la tarifa «Junela» de «Pool Palcoyo» → Junela. Son dos empresas y
 * se reponen a mano; no se siembran aquí porque el resto del tarifario también hay que llenarlo y
 * un backfill de dos filas no ahorra ese trabajo.
 */
final class Version20260820140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TravelTarifa: prestador, prestadorServicio y comprador — por línea de precio.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE travel_tarifa
            ADD prestador_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\',
            ADD prestador_servicio_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\',
            ADD comprador_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\'');

        $this->addSql('CREATE INDEX IDX_28AB0FE0D2DB9D53 ON travel_tarifa (prestador_id)');
        $this->addSql('CREATE INDEX IDX_28AB0FE06B93C1A ON travel_tarifa (prestador_servicio_id)');
        $this->addSql('CREATE INDEX IDX_28AB0FE0200A5E25 ON travel_tarifa (comprador_id)');

        // ON DELETE SET NULL: borrar una organización no puede llevarse por delante un precio.
        $this->addSql('ALTER TABLE travel_tarifa
            ADD CONSTRAINT FK_28AB0FE0D2DB9D53 FOREIGN KEY (prestador_id)
                REFERENCES travel_organizacion (id) ON DELETE SET NULL,
            ADD CONSTRAINT FK_28AB0FE06B93C1A FOREIGN KEY (prestador_servicio_id)
                REFERENCES travel_organizacion_servicio (id) ON DELETE SET NULL,
            ADD CONSTRAINT FK_28AB0FE0200A5E25 FOREIGN KEY (comprador_id)
                REFERENCES travel_organizacion (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE travel_tarifa
            DROP FOREIGN KEY FK_28AB0FE0D2DB9D53,
            DROP FOREIGN KEY FK_28AB0FE06B93C1A,
            DROP FOREIGN KEY FK_28AB0FE0200A5E25');
        $this->addSql('DROP INDEX IDX_28AB0FE0D2DB9D53 ON travel_tarifa');
        $this->addSql('DROP INDEX IDX_28AB0FE06B93C1A ON travel_tarifa');
        $this->addSql('DROP INDEX IDX_28AB0FE0200A5E25 ON travel_tarifa');
        $this->addSql('ALTER TABLE travel_tarifa DROP prestador_id, DROP prestador_servicio_id, DROP comprador_id');
    }
}
