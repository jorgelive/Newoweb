<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Semilla i18n del pie de las tarjetas del catálogo público.
 *
 * Sustituye al CTA naranja "Ver programa / Día a día, incluye y precios": ahora la
 * tarjeta entera es el enlace y el pie lleva una sola línea. Mismo patrón idempotente
 * que Version20260803140000 (ver docs/Cotizaciones.md §6, gotcha i18n).
 */
final class Version20260803160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Semilla i18n cat_ver_detalles (pie de tarjeta del catálogo público).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO pax_ui_i18n (id, scope, contenido, created_at, updated_at) VALUES
            ('cat_ver_detalles', 'cotizacion','[{"content": "Ver detalles de la experiencia", "language": "es"}, {"content": "See experience details", "language": "en"}, {"content": "Ver detalhes da experiência", "language": "pt"}, {"content": "Voir les détails de l''expérience", "language": "fr"}, {"content": "Vedi i dettagli dell''esperienza", "language": "it"}, {"content": "Details des Erlebnisses ansehen", "language": "de"}, {"content": "Bekijk details van de ervaring", "language": "nl"}]', NOW(), NOW())
            AS nuevo
            ON DUPLICATE KEY UPDATE
                contenido  = IF(JSON_LENGTH(pax_ui_i18n.contenido) > 0, pax_ui_i18n.contenido, nuevo.contenido),
                updated_at = NOW()
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM pax_ui_i18n WHERE id = 'cat_ver_detalles'");
    }
}
