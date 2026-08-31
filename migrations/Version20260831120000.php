<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `fin_enlace_pago.cliente_apellido`: el apellido deja de perderse por el camino.
 *
 * ── Por qué ─────────────────────────────────────────────────────────────────
 * La reserva guarda `nombre_cliente` y `apellido_cliente` en dos columnas, y
 * `PmsReservaOrigenCobroResolver` los pegaba con `getNombreApellido()` antes de crear el enlace.
 * De ahí para abajo sólo había un campo, así que a la pasarela le llegaba «Vanesa Acosta» en
 * `first_name` y nada en `last_name`.
 *
 * Recuperar el apellido al final era adivinar dónde empieza, y no hay heurística que acierte:
 * `RGRABK` tiene nombre «Ramos Garcia» y apellido «Mª Isabel». La solución no es partir mejor,
 * es dejar de juntarlos.
 *
 * ── Por qué no se rellena hacia atrás ───────────────────────────────────────
 * Los 21 enlaces anteriores conservan el nombre completo en `cliente_nombre`. Partirlo ahora
 * sería exactamente la adivinanza que este cambio viene a evitar, y sobre datos que ya se
 * mandaron a la pasarela. Se quedan como están y se leen igual: el panel de Culqi concatena los
 * dos campos.
 *
 * ── Por qué va por migración y no por comando ───────────────────────────────
 * Es una columna nueva y nada más: ningún listener la mira y ninguna entidad la recalcula al
 * guardarse. Ver la tabla de CLAUDE.md.
 */
final class Version20260831120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'fin_enlace_pago: columna cliente_apellido, para no perder el apellido del origen.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fin_enlace_pago ADD cliente_apellido VARCHAR(150) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fin_enlace_pago DROP cliente_apellido');
    }
}
