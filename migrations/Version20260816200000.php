<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `comprador`: el tercer rol, a quién se le encarga EJECUTAR la compra.
 *
 * ── Qué problema cierra ─────────────────────────────────────────────────────
 * La Orden de Servicio se dirigía al PROVEEDOR, o sea a quien pone el precio. Suele
 * valer, porque le compras directo — pero no siempre: la tarifa del boleto turístico es
 * del Cosituc y a Cosituc no se le manda un correo para que te venda. Hay que encargarle
 * la compra a alguien de casa o a un tercero que la gestione, y eso no tenía dónde
 * escribirse.
 *
 * ── Siempre un proveedor, nunca una persona ─────────────────────────────────
 * `comprador_maestro_id` es un soft-link a `travel_proveedor`, y sólo a eso. También los
 * compradores internos: «Openperu tickets» es una parte de la empresa modelada como
 * proveedor. El chófer presta servicio como empresa de transportes, no como persona
 * natural, así que un segundo catálogo de personas duplicaría el mismo hecho y obligaría a
 * preguntar «¿de qué clase es este comprador?» antes de poder elegir uno.
 *
 * El caso real: le encargas a Futurismo que compre las entradas a San Francisco o a
 * Paracas, o que contrate el Hotel Estelar porque consigue mejor precio — ahí el prestador
 * es el hotel y el comprador es Futurismo. Y la excursión del propio Futurismo no lleva
 * comprador, porque ésa se la compras tú directo.
 *
 * ── Adición pura, sin backfill ──────────────────────────────────────────────
 * No hay nada que migrar: el dato no existía. Las columnas nacen vacías y
 * `resolverComprador()` cae al proveedor mientras lo estén, así que **el comportamiento de
 * hoy no cambia en absoluto** — la Orden de Servicio se sigue dirigiendo a quien se dirigía
 * hasta que alguien encargue la compra explícitamente.
 *
 * Sin cara pública: ninguna de estas columnas llega a la vista del cliente. A quién le
 * encargaste la compra no es asunto suyo.
 */
final class Version20260816200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cotcomponente: comprador (a quién se le encarga ejecutar la compra).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_cotcomponente
            ADD comprador_maestro_id VARCHAR(36) DEFAULT NULL,
            ADD comprador_nombre_snapshot VARCHAR(150) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_cotcomponente
            DROP comprador_maestro_id,
            DROP comprador_nombre_snapshot');
    }
}
