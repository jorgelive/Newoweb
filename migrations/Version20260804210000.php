<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Semillas i18n de los títulos de bloque del escaparate (`PmsCatalogoBloque`).
 *
 * El backend manda el TIPO del bloque, no su texto: la página es pública y
 * multiidioma, así que el título se resuelve en el front por
 * `cat_bloque_<tipo>`. Añadir un caso al enum obliga a sembrar aquí su etiqueta
 * y a añadirla al mapa de respaldo de CatalogoUnidadView.
 *
 * `destacado` va deliberadamente VACÍO: es el gancho de la página y se pinta sin
 * encabezado. Se siembra igual para que el editor sepa que existe y pueda darle
 * título si algún día lo quiere.
 */
final class Version20260804210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Semillas i18n de los titulos de bloque del catalogo publico.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO pax_ui_i18n (id, scope, contenido, created_at, updated_at) VALUES
            ('cat_bloque_destacado', 'catalogo', '[{"content": "", "language": "es"}, {"content": "", "language": "en"}, {"content": "", "language": "pt"}, {"content": "", "language": "fr"}, {"content": "", "language": "it"}, {"content": "", "language": "de"}, {"content": "", "language": "nl"}]', NOW(), NOW()),
            ('cat_bloque_descripcion', 'catalogo', '[{"content": "El alojamiento", "language": "es"}, {"content": "The place", "language": "en"}, {"content": "O alojamento", "language": "pt"}, {"content": "Le logement", "language": "fr"}, {"content": "L''alloggio", "language": "it"}, {"content": "Die Unterkunft", "language": "de"}, {"content": "De accommodatie", "language": "nl"}]', NOW(), NOW()),
            ('cat_bloque_fotos', 'catalogo', '[{"content": "Fotos", "language": "es"}, {"content": "Photos", "language": "en"}, {"content": "Fotos", "language": "pt"}, {"content": "Photos", "language": "fr"}, {"content": "Foto", "language": "it"}, {"content": "Fotos", "language": "de"}, {"content": "Foto''s", "language": "nl"}]', NOW(), NOW()),
            ('cat_bloque_servicios', 'catalogo', '[{"content": "Servicios y equipamiento", "language": "es"}, {"content": "Amenities", "language": "en"}, {"content": "Comodidades", "language": "pt"}, {"content": "Équipements", "language": "fr"}, {"content": "Servizi e dotazioni", "language": "it"}, {"content": "Ausstattung", "language": "de"}, {"content": "Voorzieningen", "language": "nl"}]', NOW(), NOW()),
            ('cat_bloque_ubicacion', 'catalogo', '[{"content": "Ubicación", "language": "es"}, {"content": "Location", "language": "en"}, {"content": "Localização", "language": "pt"}, {"content": "Emplacement", "language": "fr"}, {"content": "Posizione", "language": "it"}, {"content": "Lage", "language": "de"}, {"content": "Locatie", "language": "nl"}]', NOW(), NOW()),
            ('cat_bloque_normas', 'catalogo', '[{"content": "Antes de reservar", "language": "es"}, {"content": "Before you book", "language": "en"}, {"content": "Antes de reservar", "language": "pt"}, {"content": "Avant de réserver", "language": "fr"}, {"content": "Prima di prenotare", "language": "it"}, {"content": "Vor der Buchung", "language": "de"}, {"content": "Voordat je boekt", "language": "nl"}]', NOW(), NOW())
            AS nuevo
            ON DUPLICATE KEY UPDATE
                contenido = nuevo.contenido,
                updated_at = NOW()
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM pax_ui_i18n WHERE id LIKE 'cat_bloque_%'");
    }
}
