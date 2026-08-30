<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El estado `archivado` de una COTIZACIÓN pasa a llamarse `cerrado`.
 *
 * ── Por qué ─────────────────────────────────────────────────────────────────
 * `FileEstadoEnum::ARCHIVADO` es la venta GANADA y `CotizacionEstadoEnum::ARCHIVADO` era la
 * propuesta que se aparta sin vender: la misma palabra para resultados opuestos, y las dos
 * conviviendo en la misma pantalla —el expediente arriba, sus versiones debajo—. El vocabulario
 * que gana es el del chat, que es el que más se usa: lo bueno es archivado, lo malo es cerrado.
 *
 * ── Por qué va por migración y no por comando ───────────────────────────────
 * Es renombrar el VALOR de un enum, no cambiar de estado. Pasarlo por el ORM dispararía
 * `CotizacionConfirmadaEventListener`, que al ver un cambio en `estado` cancelaría otra vez las
 * filas de operación de esas cotizaciones — un trabajo ya hecho el día que entraron en este
 * estado, y que reabriría el historial de cada fila sin motivo. Aquí el significado no se toca:
 * sólo la palabra. Ver la tabla de CLAUDE.md.
 */
final class Version20260830060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "cotizacion_cotizacion.estado: 'archivado' → 'cerrado' (coherencia con el chat y el expediente).";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE cotizacion_cotizacion SET estado = 'cerrado' WHERE estado = 'archivado'");
    }

    public function down(Schema $schema): void
    {
        // Sólo las de cotización: `cotizacion_file.estado` tiene su propio 'cerrado' y significa
        // otra cosa. Este WHERE no lo toca porque es otra tabla, y conviene que quede dicho.
        $this->addSql("UPDATE cotizacion_cotizacion SET estado = 'archivado' WHERE estado = 'cerrado'");
    }
}
