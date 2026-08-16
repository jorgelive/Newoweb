<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Retira de la tarifa la última referencia al proveedor: nadie la leía.
 *
 * ── Por qué se había conservado, y por qué no se sostiene ───────────────────
 * `Version20260816160000` subió la presentación del proveedor al componente y dejó aquí
 * `proveedor_maestro_id` + `proveedor_nombre_snapshot` con el argumento de que responden
 * «¿de quién es ESTE precio?» —comparar la tarifa de un consorcio contra la de un
 * revendedor dentro del mismo componente— y que eso sí es por línea.
 *
 * Al rastrear los lectores no había ninguno: el editor las escribía y ahí morían. Los
 * únicos consumidores en PHP leen las del **componente**. Era el mismo patrón que este
 * refactor lleva desmontando toda la sesión —un campo escrito y desconectado—, sólo que
 * reintroducido con buena intención.
 *
 * Y los datos nunca respaldaron el caso: cero componentes con dos proveedores distintos
 * entre sus tarifas, ni en la cotización ni en el catálogo maestro.
 *
 * ── Si algún día hace falta de verdad ───────────────────────────────────────
 * El día que se quiera comparar precios de proveedores distintos dentro de un componente,
 * lo que hay que añadir no es sólo la columna: hace falta enseñarlo en el chip de la
 * tarifa. Una columna sin lector no es media función, es ninguna.
 *
 * El dato no se pierde: el proveedor del componente lo tiene, y era el mismo en todos los
 * casos existentes.
 */
final class Version20260816250000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cottarifa: retira la referencia al proveedor, que no tenía lectores.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_cottarifa
            DROP proveedor_maestro_id,
            DROP proveedor_nombre_snapshot');
    }

    public function down(Schema $schema): void
    {
        // Vuelven rellenas desde el componente, que es de donde salieron.
        $this->addSql('ALTER TABLE cotizacion_cottarifa
            ADD proveedor_maestro_id VARCHAR(36) DEFAULT NULL,
            ADD proveedor_nombre_snapshot VARCHAR(150) DEFAULT NULL');
        $this->addSql('UPDATE cotizacion_cottarifa t
            JOIN cotizacion_cotcomponente c ON c.id = t.cotcomponente_id
            SET t.proveedor_maestro_id = c.proveedor_maestro_id,
                t.proveedor_nombre_snapshot = c.proveedor_nombre_snapshot');
    }
}
