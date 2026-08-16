<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `travel_proveedor.visible_para_cliente`: la visibilidad deja de deducirse del título.
 *
 * ── Qué estaba mal ──────────────────────────────────────────────────────────
 * No había bandera. La regla era «si no tiene título, el cliente no lo ve», y estaba
 * escrita como una ventaja deliberada —un booleano menos que mantener— en
 * `util/src/types/proveedorModel.ts`. Tres consecuencias que no se vieron al decidirlo:
 *
 *   1. **Ocultar era destructivo.** El único modo de esconder a un proveedor ya
 *      publicado era borrarle el título, que es la única copia del texto. Volver a
 *      mostrarlo obligaba a reescribirlo.
 *   2. **Nadie decidió el estado actual.** 93 de 98 proveedores están invisibles porque
 *      nunca se les llenó el título, no porque se quisiera ocultarlos.
 *   3. **La intención no se podía expresar.** «Este proveedor tiene título pero en esta
 *      propuesta no lo nombres» no tenía dónde escribirse.
 *
 * ── Esta migración NO cambia lo que ve ningún cliente ───────────────────────
 * El backfill copia la regla vieja: visible ⟺ tenía título. Los mismos 5 proveedores que
 * hoy pueden mostrarse siguen pudiendo, y los 93 restantes siguen sin poder. Lo que
 * cambia es que a partir de ahora es un dato y no un efecto secundario.
 *
 * ── Por qué el default es `false` ───────────────────────────────────────────
 * Invierte a propósito el criterio de «lista vacía = sin acotar» que rige en guía y
 * conocimiento. Allí el olvido deja un ítem de más y es inofensivo; aquí nombrar a un
 * proveedor que no tocaba invita al cliente a saltarse la intermediación. El olvido caro
 * es el contrario, así que se entra por opt-in.
 *
 * Alcance: sólo el maestro. La bandera por cotización (`<rol>Visible` en los snapshots)
 * va aparte — ésta es la semilla que aquélla copiará al asignar, no un veto vivo.
 */
final class Version20260816120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Proveedor: bandera explícita de visibilidad al cliente, sembrada desde el título existente.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE travel_proveedor
            ADD visible_para_cliente TINYINT(1) DEFAULT 0 NOT NULL');

        // Preserva el comportamiento actual: los que hoy se ven son los que tenían título.
        // JSON_LENGTH sobre una columna `json` NOT NULL basta; el IS NULL es defensa por si
        // alguna fila vieja quedó con NULL antes de que la columna se declarara obligatoria.
        $this->addSql('UPDATE travel_proveedor
            SET visible_para_cliente = 1
            WHERE titulo IS NOT NULL AND JSON_LENGTH(titulo) > 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE travel_proveedor DROP visible_para_cliente');
    }
}
