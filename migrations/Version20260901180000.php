<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `fin_medio_cobro.prioritario`: cuáles se le enseñan al huésped.
 *
 * ── Por qué ─────────────────────────────────────────────────────────────────
 * Detrás de la «i» de «Transferencia bancaria» salían ocho cuentas —BCP, BBVA, Interbank y
 * Scotiabank, cada una en soles y en dólares— y el huésped sólo busca la suya. Una lista de ocho
 * es la que hace que no se lea ninguna.
 *
 * ── Por qué las dos de DÓLARES ──────────────────────────────────────────────
 * Decisión de Jorge (01/09/2026): BCP e Interbank, ambas en USD. Los cargos son en dólares, así
 * que cobrar en dólares **quita de raíz el cruce de monedas** — que es lo que hoy tiene a
 * `XTHRMQ`, `GASUNN` y `V6WDDQ` esperando una imputación a mano porque alguien pagó en soles una
 * cuenta emitida en dólares (§12.2b).
 *
 * ── Por qué no se tocan Yape, Plin, Western Union ni el efectivo ────────────
 * El filtro es POR TIPO: si algún medio de un tipo está marcado, se enseñan sólo los marcados;
 * si ninguno lo está, se enseñan todos. Marcando las dos transferencias, el resto sigue saliendo
 * solo. Es lo que evita que añadir un tipo nuevo lo deje invisible por olvido.
 *
 * ── Por qué va por migración y no por comando ───────────────────────────────
 * Una columna y dos banderas. No lleva `#[AutoTranslate]`, ningún listener la mira y ninguna
 * entidad la recalcula al guardarse. Ver la tabla de CLAUDE.md.
 */
final class Version20260901180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'fin_medio_cobro: columna prioritario, marcadas BCP USD e Interbank USD.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fin_medio_cobro ADD prioritario TINYINT(1) DEFAULT 0 NOT NULL');

        // Por NÚMERO de cuenta y no por banco: «BCP» tiene dos filas —soles y dólares— y la que
        // se quiere es la de dólares. El número es lo único que las distingue sin ambigüedad.
        $this->addSql("UPDATE fin_medio_cobro SET prioritario = 1 WHERE numero IN ('285-03306263-1-25', '4263078214241')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fin_medio_cobro DROP prioritario');
    }
}
