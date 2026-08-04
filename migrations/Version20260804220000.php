<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Semillas i18n que faltaban en la vista pública de la reserva, y título del
 * bloque «destacado» del escaparate.
 *
 * - `res_ver_mas` / `res_ver_menos`: el desplegable del detalle de pagos de
 *   PmsReservaView. Se pedían por `maestroStore.t()` pero nunca se sembraron, así
 *   que siempre caían al respaldo en español — en una página que es multiidioma.
 * - `cat_bloque_destacado`: Version20260804210000 lo sembró VACÍO a propósito
 *   (la página pública pinta ese bloque sin encabezado, ver `esDestacado()` en
 *   CatalogoUnidadView). Sin texto, en el editor la fila aparecía en blanco y no
 *   se sabía qué se estaba editando; ahora lleva etiqueta. La vista pública sigue
 *   sin pintarla: quien decide es `esDestacado()`, no este contenido.
 *
 * `ON DUPLICATE KEY UPDATE` para que valga como alta y como corrección: es la
 * misma forma que usan las demás migraciones de semillas i18n.
 */
final class Version20260804220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'i18n: res_ver_mas / res_ver_menos y titulo del bloque destacado.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO pax_ui_i18n (id, scope, contenido, created_at, updated_at) VALUES
            ('res_ver_mas', 'reserva', '[{"content": "Mostrar más", "language": "es"}, {"content": "Show more", "language": "en"}, {"content": "Mostrar mais", "language": "pt"}, {"content": "Afficher plus", "language": "fr"}, {"content": "Mostra di più", "language": "it"}, {"content": "Mehr anzeigen", "language": "de"}, {"content": "Meer tonen", "language": "nl"}]', NOW(), NOW()),
            ('res_ver_menos', 'reserva', '[{"content": "Mostrar menos", "language": "es"}, {"content": "Show less", "language": "en"}, {"content": "Mostrar menos", "language": "pt"}, {"content": "Afficher moins", "language": "fr"}, {"content": "Mostra meno", "language": "it"}, {"content": "Weniger anzeigen", "language": "de"}, {"content": "Minder tonen", "language": "nl"}]', NOW(), NOW()),
            ('cat_bloque_destacado', 'catalogo', '[{"content": "Lo destacado", "language": "es"}, {"content": "Highlights", "language": "en"}, {"content": "Destaques", "language": "pt"}, {"content": "Les points forts", "language": "fr"}, {"content": "In evidenza", "language": "it"}, {"content": "Highlights", "language": "de"}, {"content": "Hoogtepunten", "language": "nl"}]', NOW(), NOW())
            AS nuevo
            ON DUPLICATE KEY UPDATE
                contenido = nuevo.contenido,
                updated_at = NOW()
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM pax_ui_i18n WHERE id IN ('res_ver_mas', 'res_ver_menos')");

        // El bloque destacado no se borra: existía antes de esta migración. Se
        // devuelve al contenido vacío con el que lo dejó Version20260804210000.
        $this->addSql(<<<'SQL'
            UPDATE pax_ui_i18n
               SET contenido = '[{"content": "", "language": "es"}, {"content": "", "language": "en"}, {"content": "", "language": "pt"}, {"content": "", "language": "fr"}, {"content": "", "language": "it"}, {"content": "", "language": "de"}, {"content": "", "language": "nl"}]',
                   updated_at = NOW()
             WHERE id = 'cat_bloque_destacado'
            SQL);
    }
}
