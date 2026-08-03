<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Título del aviso de estancia finalizada en la guía del huésped.
 *
 * Se quedó fuera de Version20260803235000: al recuperar la navegación de 3
 * niveles, el aviso de acceso pasó a tener título propio además del texto.
 */
final class Version20260803235500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Semilla i18n gui_acceso_expirada_titulo.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO pax_ui_i18n (id, scope, contenido, created_at, updated_at) VALUES
            ('gui_acceso_expirada_titulo', 'guia', '[{"content": "Estancia finalizada", "language": "es"}, {"content": "Stay ended", "language": "en"}, {"content": "Estadia terminada", "language": "pt"}, {"content": "Séjour terminé", "language": "fr"}, {"content": "Soggiorno terminato", "language": "it"}, {"content": "Aufenthalt beendet", "language": "de"}, {"content": "Verblijf beëindigd", "language": "nl"}]', NOW(), NOW())
            AS nuevo
            ON DUPLICATE KEY UPDATE
                contenido  = IF(JSON_LENGTH(pax_ui_i18n.contenido) > 0, pax_ui_i18n.contenido, nuevo.contenido),
                updated_at = NOW()
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM pax_ui_i18n WHERE id = 'gui_acceso_expirada_titulo'");
    }
}
