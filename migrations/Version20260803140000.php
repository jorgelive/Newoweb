<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Semillas i18n que faltaban en pax_ui_i18n.
 *
 * Sin fila en la tabla, `maestroStore.t()` devuelve vacío y la vista cae al literal
 * castellano del template: el cliente extranjero ve esa cadena suelta en español. Estas
 * seis se detectaron cruzando las claves usadas en `pax/src` contra los ids de la tabla.
 *
 * Idempotente y no destructiva: si la clave ya existe **con contenido**, se respeta lo que
 * haya (puede haberse editado a mano); sólo se rellena si está vacía. Así puede re-ejecutarse
 * sin romper por clave duplicada ni pisar traducciones revisadas.
 *
 * Idiomas: los mismos 7 que traen las filas existentes (es, en, pt, fr, it, de, nl).
 */
final class Version20260803140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Semillas i18n faltantes de la guía pax (precios por perfil, horario, inclusiones).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO pax_ui_i18n (id, scope, contenido, created_at, updated_at) VALUES
            ('cot_ver_precios_perfil', 'cotizacion', '[{"content": "Toca para ver precios por perfil", "language": "es"}, {"content": "Tap to see prices by profile", "language": "en"}, {"content": "Toque para ver preços por perfil", "language": "pt"}, {"content": "Appuyez pour voir les prix par profil", "language": "fr"}, {"content": "Tocca per vedere i prezzi per profilo", "language": "it"}, {"content": "Tippen für Preise nach Profil", "language": "de"}, {"content": "Tik voor prijzen per profiel", "language": "nl"}]', NOW(), NOW()),
            ('cot_ocultar_precios', 'cotizacion', '[{"content": "Toca para ocultar", "language": "es"}, {"content": "Tap to hide", "language": "en"}, {"content": "Toque para ocultar", "language": "pt"}, {"content": "Appuyez pour masquer", "language": "fr"}, {"content": "Tocca per nascondere", "language": "it"}, {"content": "Tippen zum Ausblenden", "language": "de"}, {"content": "Tik om te verbergen", "language": "nl"}]', NOW(), NOW()),
            ('cot_pasajero', 'cotizacion', '[{"content": "pasajero", "language": "es"}, {"content": "passenger", "language": "en"}, {"content": "passageiro", "language": "pt"}, {"content": "passager", "language": "fr"}, {"content": "passeggero", "language": "it"}, {"content": "Passagier", "language": "de"}, {"content": "passagier", "language": "nl"}]', NOW(), NOW()),
            ('cot_horario_excursion', 'cotizacion', '[{"content": "Horario de la excursión", "language": "es"}, {"content": "Excursion schedule", "language": "en"}, {"content": "Horário da excursão", "language": "pt"}, {"content": "Horaires de l''excursion", "language": "fr"}, {"content": "Orario dell''escursione", "language": "it"}, {"content": "Zeitplan des Ausflugs", "language": "de"}, {"content": "Tijdschema van de excursie", "language": "nl"}]', NOW(), NOW()),
            ('cot_inclusiones_servicio', 'cotizacion', '[{"content": "¿Qué incluye este servicio?", "language": "es"}, {"content": "What does this service include?", "language": "en"}, {"content": "O que inclui este serviço?", "language": "pt"}, {"content": "Que comprend ce service ?", "language": "fr"}, {"content": "Cosa include questo servizio?", "language": "it"}, {"content": "Was beinhaltet dieser Service?", "language": "de"}, {"content": "Wat omvat deze service?", "language": "nl"}]', NOW(), NOW()),
            ('cot_ver_menos', 'cotizacion', '[{"content": "Ver menos", "language": "es"}, {"content": "Show less", "language": "en"}, {"content": "Ver menos", "language": "pt"}, {"content": "Voir moins", "language": "fr"}, {"content": "Mostra meno", "language": "it"}, {"content": "Weniger anzeigen", "language": "de"}, {"content": "Minder tonen", "language": "nl"}]', NOW(), NOW())
            AS nuevo
            ON DUPLICATE KEY UPDATE
                contenido  = IF(JSON_LENGTH(pax_ui_i18n.contenido) > 0, pax_ui_i18n.contenido, nuevo.contenido),
                updated_at = NOW()
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Sólo borra las claves que esta migración introduce. Si alguna se editó después,
        // se pierde la edición: son cadenas de UI, se vuelven a sembrar re-ejecutando up().
        $this->addSql(<<<'SQL'
            DELETE FROM pax_ui_i18n WHERE id IN (
                'cot_ver_precios_perfil', 'cot_ocultar_precios', 'cot_pasajero',
                'cot_horario_excursion', 'cot_inclusiones_servicio', 'cot_ver_menos'
            )
        SQL);
    }
}
