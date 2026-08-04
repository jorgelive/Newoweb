<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Semilla i18n del bloque «Otros alojamientos» del catálogo público.
 *
 * Encabeza la tira de casitas hermanas que añade PmsUnidadCatalogoProvider.
 * Idempotente y no destructiva, igual que las demás semillas de `pax_ui_i18n`.
 */
final class Version20260804190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Semilla i18n del bloque "Otros alojamientos" del catalogo publico.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO pax_ui_i18n (id, scope, contenido, created_at, updated_at) VALUES
            ('cat_otras_unidades', 'catalogo', '[{"content": "Otros alojamientos", "language": "es"}, {"content": "Other places to stay", "language": "en"}, {"content": "Outras acomodações", "language": "pt"}, {"content": "Autres hébergements", "language": "fr"}, {"content": "Altri alloggi", "language": "it"}, {"content": "Weitere Unterkünfte", "language": "de"}, {"content": "Andere accommodaties", "language": "nl"}]', NOW(), NOW())
            AS nuevo
            ON DUPLICATE KEY UPDATE
                contenido = nuevo.contenido,
                updated_at = NOW()
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM pax_ui_i18n WHERE id = 'cat_otras_unidades'");
    }
}
