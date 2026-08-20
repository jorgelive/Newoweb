<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * «Real» desaparece de La Biblia: cada campo pasa a llamarse lo que es.
 *
 * `real` describía tres cosas distintas y ninguna con precisión:
 *
 * | Antes | Ahora | Por qué |
 * |---|---|---|
 * | `prestador_real_*`, `comprador_real_*` | `*_override_*` | no es «lo real», es **lo que anula** a lo cotizado. Vacío = sin cambio |
 * | `hora_recojo_real` | `hora_recojo` | no hay otra hora de recojo con la que confundirla; el par es con `hora_componente`, la vendida |
 * | `costo_real_operativo` | `costo_negociado` | dice de dónde sale la cifra: de negociar, no de una verdad superior |
 * | `moneda_real` | `moneda_negociada` | va con el costo de arriba; dejarla en «real» la habría dejado huérfana |
 *
 * ⚠️ **Todo por `CHANGE`, ninguno por `ADD` + `DROP`.** Doctrine propone lo segundo para cinco de
 * ellos —lo dice `doctrine:schema:update --dump-sql`— y eso **borra el contenido**: crea la
 * columna nueva vacía y tira la vieja con lo que hubiera dentro. Es la misma trampa que en el
 * renombrado de `Proveedor` (`Version20260819230000`), y la razón de que esta migración esté
 * escrita a mano.
 *
 * La clave foránea de la moneda se recrea porque su nombre se deriva del de la columna.
 */
final class Version20260820220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'OperacionServicio: «real» pasa a override / hora_recojo / costo_negociado.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_servicio DROP FOREIGN KEY FK_E7842D256D5B3B9D');
        $this->addSql('DROP INDEX IDX_E7842D256D5B3B9D ON operacion_servicio');

        $this->addSql('ALTER TABLE operacion_servicio
            CHANGE prestador_real_maestro_id prestador_override_maestro_id VARCHAR(36) DEFAULT NULL,
            CHANGE prestador_real_nombre prestador_override_nombre VARCHAR(150) DEFAULT NULL,
            CHANGE prestador_servicio_real_maestro_id prestador_servicio_override_maestro_id VARCHAR(36) DEFAULT NULL,
            CHANGE prestador_servicio_real_nombre prestador_servicio_override_nombre VARCHAR(255) DEFAULT NULL,
            CHANGE comprador_real_maestro_id comprador_override_maestro_id VARCHAR(36) DEFAULT NULL,
            CHANGE comprador_real_nombre comprador_override_nombre VARCHAR(150) DEFAULT NULL,
            CHANGE hora_recojo_real hora_recojo VARCHAR(10) DEFAULT NULL,
            CHANGE costo_real_operativo costo_negociado NUMERIC(12, 2) NOT NULL,
            CHANGE moneda_real moneda_negociada VARCHAR(3) DEFAULT NULL');

        $this->addSql('CREATE INDEX IDX_E7842D253D19A238 ON operacion_servicio (moneda_negociada)');
        $this->addSql('ALTER TABLE operacion_servicio
            ADD CONSTRAINT FK_E7842D253D19A238 FOREIGN KEY (moneda_negociada) REFERENCES maestro_moneda (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_servicio DROP FOREIGN KEY FK_E7842D253D19A238');
        $this->addSql('DROP INDEX IDX_E7842D253D19A238 ON operacion_servicio');

        $this->addSql('ALTER TABLE operacion_servicio
            CHANGE prestador_override_maestro_id prestador_real_maestro_id VARCHAR(36) DEFAULT NULL,
            CHANGE prestador_override_nombre prestador_real_nombre VARCHAR(150) DEFAULT NULL,
            CHANGE prestador_servicio_override_maestro_id prestador_servicio_real_maestro_id VARCHAR(36) DEFAULT NULL,
            CHANGE prestador_servicio_override_nombre prestador_servicio_real_nombre VARCHAR(255) DEFAULT NULL,
            CHANGE comprador_override_maestro_id comprador_real_maestro_id VARCHAR(36) DEFAULT NULL,
            CHANGE comprador_override_nombre comprador_real_nombre VARCHAR(150) DEFAULT NULL,
            CHANGE hora_recojo hora_recojo_real VARCHAR(10) DEFAULT NULL,
            CHANGE costo_negociado costo_real_operativo NUMERIC(12, 2) NOT NULL,
            CHANGE moneda_negociada moneda_real VARCHAR(3) DEFAULT NULL');

        $this->addSql('CREATE INDEX IDX_E7842D256D5B3B9D ON operacion_servicio (moneda_real)');
        $this->addSql('ALTER TABLE operacion_servicio
            ADD CONSTRAINT FK_E7842D256D5B3B9D FOREIGN KEY (moneda_real) REFERENCES maestro_moneda (id)');
    }
}
