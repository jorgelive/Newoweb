<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Retira los cargos en 0.00 que Beds24 mandó para pre-reservas (inquiry).
 *
 * ### Qué pasaba
 *
 * Beds24 manda `invoiceItems` también para los inquiry de Airbnb: siempre en **0.00** y con
 * la descripción sin resolver (`[ROOMNAME1] [FIRSTNIGHT] - [LEAVINGDAY]`). El panel de la
 * reserva mostraba «Cargos (1)» y una línea de US$ 0.00 que no informa de nada y que hace
 * parecer que una pre-reserva ya tiene contabilidad.
 *
 * Desde ahora no se crean (`Beds24InvoiceReceivePersister::upsertCargos()`); esto se lleva los
 * tres que quedaron.
 *
 * ### Por qué se puede borrar por SQL sin recalcular nada
 *
 * Porque **no cambia ni un número**. El filtro exige `total_linea = 0`, así que
 * `total_cargos`, el saldo, el estado de pago y el depósito automático de la OTA
 * (`getTotalCargosDelCanal()`) dan exactamente lo mismo con o sin estas filas. No hay caché
 * que refrescar ni listener que deba correr.
 *
 * Las tres condiciones juntas son lo que lo hace seguro, y ninguna sobra:
 *
 * - `estado_id = 'abierto'` — que es EXACTAMENTE `inquiry`, y nada más (ver el mapeo de
 *   `pms_evento_estado.codigo_beds24`);
 * - `beds24_item_id IS NOT NULL` — sólo cargos del canal, nunca uno manual del operador;
 * - `total_linea = 0` — si algún inquiry trajera dinero de verdad, **no se toca**.
 *
 * ⚠️ Y por eso va por SQL y no por el ORM: `assertCargoBorrable()` veta borrar cargos
 * sincronizados de Beds24, con razón —el siguiente pull los recrearía—. Aquí no los recrea,
 * porque el persister ya no los crea para inquiry.
 *
 * ### Si una de esas pre-reservas se convierte
 *
 * No se pierde nada: el webhook de la conversión trae los invoiceItems con sus importes
 * reales y, como el handler procesa `booking` antes que `invoiceItems`, la estancia ya no es
 * `abierto` y el cargo entra normal.
 */
final class Version20260826180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Retira los cargos en 0.00 que Beds24 creó para pre-reservas (inquiry).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE c FROM pms_cargo_financiero c
            INNER JOIN pms_informacion_financiera i ON i.id = c.informacion_id
            INNER JOIN pms_evento_calendario e ON e.reserva_id = i.reserva_id
            WHERE e.estado_id = 'abierto'
              AND c.beds24_item_id IS NOT NULL
              AND c.total_linea = 0
        SQL);
    }

    public function down(Schema $schema): void
    {
        // No se revierte: eran filas en 0.00 sin significado contable, y el canal las
        // volvería a mandar si esas reservas dejan de ser pre-reservas.
        $this->write('  -> No se revierte: eran cargos en 0.00 de pre-reservas.');
    }
}
