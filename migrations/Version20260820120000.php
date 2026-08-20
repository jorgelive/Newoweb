<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `TravelComponente` deja de fijar un prestador.
 *
 * Lo absorbió de `TravelTarifa` hace cuatro días ({@see Version20260816240000}) con un
 * argumento de ergonomía: el campo estaba abandonado —5 de 904— porque un componente llega a
 * tener 19 tarifas y nadie repite la misma empresa 19 veces.
 *
 * El argumento era cierto y aun así la decisión era mala, porque el de corrección pesa más: **un
 * componente puede tener tarifas de empresas distintas**. «Tren Ollanta – Machu Picchu» se compra
 * a PeruRail y a IncaRail; el Boleto de Machu Picchu, al Ministerio o a un revendedor. Un
 * prestador único arriba no es un valor por defecto incómodo: es una afirmación **falsa sobre
 * todas las tarifas menos una**, y encima impedía cargar las demás.
 *
 * Quién presta el servicio es una decisión de **cotización**, no de catálogo: se resuelve al
 * elegir la tarifa, y ahí ya vive —`CotizacionCotcomponente::resolverPrestador()`—.
 *
 * Se van con él:
 * - `prestadorServicio`, que sólo existía para acotar el de arriba.
 * - `TravelComponente::validarServicioDelPrestador()`, la validación cruzada del par.
 * - El panel «Prestador por defecto» del CRUD, cuya ayuda además prometía activar un filtro de
 *   tarifas que en realidad nunca miró este campo.
 *
 * **Dato que se pierde: 2 filas de 225, y ningún `prestador_servicio`.** La lógica llevaba cuatro
 * días viva y no llegó a poblarse.
 */
final class Version20260820120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TravelComponente: fuera el prestador — un componente puede tener tarifas de varias empresas.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE travel_componente DROP FOREIGN KEY FK_4329A17F6B93C1A');
        $this->addSql('ALTER TABLE travel_componente DROP FOREIGN KEY FK_4329A17FD2DB9D53');
        $this->addSql('DROP INDEX IDX_4329A17F6B93C1A ON travel_componente');
        $this->addSql('DROP INDEX IDX_4329A17FD2DB9D53 ON travel_componente');
        $this->addSql('ALTER TABLE travel_componente DROP prestador_id, DROP prestador_servicio_id');
    }

    /**
     * Devuelve las columnas vacías. **El contenido no vuelve**, y con 2 filas no compensa
     * guardarlo: rehacer a mano dos componentes es más barato que una tabla de respaldo que
     * nadie va a mirar.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE travel_componente
            ADD prestador_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\',
            ADD prestador_servicio_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\'');
        $this->addSql('CREATE INDEX IDX_4329A17F6B93C1A ON travel_componente (prestador_id)');
        $this->addSql('CREATE INDEX IDX_4329A17FD2DB9D53 ON travel_componente (prestador_servicio_id)');
        $this->addSql('ALTER TABLE travel_componente
            ADD CONSTRAINT FK_4329A17F6B93C1A FOREIGN KEY (prestador_id)
                REFERENCES travel_organizacion (id) ON DELETE SET NULL,
            ADD CONSTRAINT FK_4329A17FD2DB9D53 FOREIGN KEY (prestador_servicio_id)
                REFERENCES travel_organizacion_servicio (id) ON DELETE SET NULL');
    }
}
