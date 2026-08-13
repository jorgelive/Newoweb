<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `estado_reserva` pasa a `estado_reserva_proveedor`: la reserva es al PROVEEDOR, no del huésped.
 *
 * En este sistema «reserva» significa {@see \App\Pms\Entity\PmsReserva}, la del huésped. La
 * columna de La Biblia guardaba lo contrario: si el proveedor me confirmó a MÍ el servicio que
 * le compré. Quien leyera `estadoReserva = confirmado` en una fila tenía todo el derecho a
 * entender que la reserva del cliente estaba confirmada.
 *
 * ── Por qué ahora ───────────────────────────────────────────────────────────
 * Porque el agente conversacional va a leer este campo para contestarle a un pasajero «¿ya está
 * confirmado mi tour?», y ahí equivocarse de estado no da un error: da una respuesta
 * tranquilizadora y falsa. El módulo sigue en diseño y casi nadie lo consume todavía, así que
 * el renombrado cuesta esta migración; en seis meses habría costado esto más una cacería de
 * literales por PHP, TypeScript y plantillas.
 *
 * ── Los tres estados que se parecen ─────────────────────────────────────────
 *   - `estado_reserva_proveedor` → ¿el proveedor ya me confirmó?  (la plaza existe)
 *   - `estado_operacion`         → ¿el servicio ya ocurrió?
 *   - `operacion_orden_servicio.estado_os` → ¿en qué punto está el papeleo de la compra?
 *
 * Sólo el primero significa algo para quien viaja.
 *
 * Los VALORES no cambian (`sin-solicitar`, `solicitado`, `confirmado`, `reconfirmado`,
 * `pendiente-pago`): es un `CHANGE` de nombre, los datos viajan intactos y `down()` lo deshace
 * sin pérdida.
 */
final class Version20260812280000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'operacion_servicio.estado_reserva → estado_reserva_proveedor (es la reserva AL proveedor)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "ALTER TABLE operacion_servicio
                CHANGE estado_reserva estado_reserva_proveedor
                VARCHAR(30) DEFAULT 'sin-solicitar' NOT NULL"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "ALTER TABLE operacion_servicio
                CHANGE estado_reserva_proveedor estado_reserva
                VARCHAR(30) DEFAULT 'sin-solicitar' NOT NULL"
        );
    }
}
