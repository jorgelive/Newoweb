<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fase 5 de `docs/PlanModalidadDeTarifa.md`: `operativo` deja de ser un ROL.
 *
 * No era de esa familia. El rol dice si una tarifa **compite** con otra de cara al cliente
 * —estándar contra alternativa— y una operativa no compite con nada: lo que decía era **cómo se
 * cuenta su dinero y si el cliente la ve**, que es `TarifaCalculoEnum`.
 *
 * Las 22 del catálogo ya tienen `calculo = 'operativa'` desde `Version20261005120000`, así que
 * esto sólo retira el valor del eje equivocado. En los snapshots no hay ninguna.
 *
 * ⚠️ **El dato no se pierde: se mudó.** Por eso el `WHERE` exige que el cálculo ya esté puesto —
 * si por lo que fuera no lo estuviera, la fila se queda como está y se ve, en vez de convertirse
 * en una estándar visible al cliente con comisión.
 */
final class Version20261005180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'operativo deja de ser un rol: el dato vive en calculo';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE travel_tarifa SET rol = 'estandar' WHERE rol = 'operativo' AND calculo = 'operativa'");
        $this->addSql("UPDATE cotizacion_cottarifa SET rol_snapshot = 'estandar' WHERE rol_snapshot = 'operativo' AND calculo_snapshot = 'operativa'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE travel_tarifa SET rol = 'operativo' WHERE calculo = 'operativa'");
        $this->addSql("UPDATE cotizacion_cottarifa SET rol_snapshot = 'operativo' WHERE calculo_snapshot = 'operativa'");
    }
}
