<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Backfill de totalesOcultos en catálogo.
 *
 * El flag nació con default false, así que los tours de catálogo ya creados muestran al
 * cliente el "2X", el "× N pax · total" y la barra "Precio total del viaje" — cifras
 * calculadas sobre el Num Pax base, que en catálogo es sólo un divisor de costos
 * compartidos, no un grupo real. Los pone en true de una vez (ver docs/Cotizaciones.md §6.b).
 */
final class Version20260803120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Activa totales_ocultos en las cotizaciones de catálogo existentes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE cotizacion_cotizacion SET totales_ocultos = 1 WHERE catalogo_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Revierte al default histórico. No distingue los tours donde el flag ya se había
        // activado a mano antes de esta migración: todos vuelven a mostrar el total.
        $this->addSql('UPDATE cotizacion_cotizacion SET totales_ocultos = 0 WHERE catalogo_id IS NOT NULL');
    }
}
