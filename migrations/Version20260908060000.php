<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El candado de imputación: `pms_cargo_financiero.imputacion_fijada`.
 *
 * 🔥 `Beds24InvoiceReceivePersister` reimputa la estancia de cada cargo en CADA sincronización, y
 * hace bien —así el cargo sigue a su casita cuando el huésped se muda—, pero convertía cualquier
 * mudanza manual en un espejismo: el pull corre cada 3–10 minutos y la deshacía en silencio.
 *
 * Y hace falta moverlos: una reserva de OTA que se cancela y sigue como directa deja sus cargos
 * colgados de una estancia que ya no cobra, y **una de OTA no se reactiva nunca**.
 *
 * ⚠️ Escrita a mano y no con `migrations:diff`: el diff arrastraba el borrado de tres tablas
 * `energia_*` que no son de este cambio.
 */
final class Version20260908060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Candado de imputación en los cargos: la mudanza manual sobrevive a la sincronización.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_cargo_financiero ADD imputacion_fijada TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_cargo_financiero DROP imputacion_fijada');
    }
}
