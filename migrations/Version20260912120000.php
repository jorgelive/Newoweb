<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La portada de la casita se muda a `pms_unidad_media`, con los demás medios.
 *
 * 🔥 **Era el mismo patrón que se retiró para el croquis**: una entidad que va acumulando los
 * medios que cuelgan de ella. `pms_unidad` guardaba su portada en `image_name` con su propio
 * `UploadableField`, su listener de URL y su listener de caché, mientras el croquis, la foto de la
 * puerta y el vídeo del ingreso vivían ya en una entidad aparte y con un vocabulario de acceso
 * propio. Ahora todos los medios de la casita están en un sitio, con un tipo y un nivel.
 *
 * ── Por qué sale barato ─────────────────────────────────────────────────────
 * El contrato no cambia: `PmsUnidad::getImageUrl()` sigue existiendo y sigue publicándose en
 * `pax_reserva:read`, `pax_guia:read` y `pax_catalogo:read`. Lo único que cambia es de dónde lee.
 * Ni la API, ni `PmsUnidadCatalogoProvider`, ni `pax` se enteran.
 *
 * ── El archivo no se mueve ──────────────────────────────────────────────────
 * `pms_unidad_media` usa el mismo mapeo de Vich (`unidad_images`) que usaba la portada, así que
 * los archivos ya están donde tienen que estar. Se mueve la FILA, no el disco.
 *
 * ⚠️ `token` se rellena aquí: el namer de Vich lo genera al subir, y estas filas no pasan por una
 * subida. Sin él, `MediaTokenNamer` no sabría cómo nombrar un reemplazo futuro.
 *
 * ⚠️ Las columnas `image_name` e `image_updated_at` de `pms_unidad` se **borran** en la misma
 * migración, a propósito: dejarlas «por si acaso» es dejar dos sitios donde mirar la portada, y en
 * un mes nadie sabría cuál manda. El `down()` las repone con su dato.
 */
final class Version20260912120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mueve la portada de pms_unidad a pms_unidad_media (tipo portada).';
    }

    public function up(Schema $schema): void
    {
        // UUID v7 no lo genera MySQL: se usa `UUID_TO_BIN(UUID())` (v1). El id sólo tiene que ser
        // único —nadie ordena estas filas por él— y son siete.
        $this->addSql("INSERT INTO pms_unidad_media
                (id, unidad_id, tipo, image_name, image_updated_at, url, orden, token, created_at, updated_at)
            SELECT UUID_TO_BIN(UUID()), u.id, 'portada', u.image_name, u.image_updated_at, NULL, 0,
                   LEFT(REPLACE(UUID(), '-', ''), 16), NOW(), NULL
              FROM pms_unidad u
             WHERE COALESCE(u.image_name, '') <> ''
               AND NOT EXISTS (SELECT 1 FROM pms_unidad_media m
                                WHERE m.unidad_id = u.id AND m.tipo = 'portada')");

        $this->addSql('ALTER TABLE pms_unidad DROP image_name, DROP image_updated_at');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_unidad ADD image_name VARCHAR(255) DEFAULT NULL, ADD image_updated_at DATETIME DEFAULT NULL');

        $this->addSql("UPDATE pms_unidad u
                         JOIN pms_unidad_media m ON m.unidad_id = u.id AND m.tipo = 'portada'
                          SET u.image_name = m.image_name,
                              u.image_updated_at = m.image_updated_at");

        $this->addSql("DELETE FROM pms_unidad_media WHERE tipo = 'portada'");
    }
}
