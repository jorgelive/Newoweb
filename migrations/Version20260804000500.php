<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rótulo de la rejilla de secciones en la portada de la guía del huésped.
 */
final class Version20260804000500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Semilla i18n gui_explora (rótulo de la rejilla de secciones).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO pax_ui_i18n (id, scope, contenido, created_at, updated_at) VALUES
            ('gui_explora', 'guia', '[{"content": "Explora tu guía", "language": "es"}, {"content": "Explore your guide", "language": "en"}, {"content": "Explore o seu guia", "language": "pt"}, {"content": "Explorez votre guide", "language": "fr"}, {"content": "Esplora la tua guida", "language": "it"}, {"content": "Entdecken Sie Ihren Guide", "language": "de"}, {"content": "Verken je gids", "language": "nl"}]', NOW(), NOW())
            AS nuevo
            ON DUPLICATE KEY UPDATE
                contenido  = IF(JSON_LENGTH(pax_ui_i18n.contenido) > 0, pax_ui_i18n.contenido, nuevo.contenido),
                updated_at = NOW()
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM pax_ui_i18n WHERE id = 'gui_explora'");
    }
}
