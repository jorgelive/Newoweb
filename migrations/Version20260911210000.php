<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Los medios de la casita, y un número en vez de tres.
 *
 * Pasos 2, 3 y 4 del plan de `docs/PmsGuiaHuesped.md` §3.c.
 *
 * ── El número ───────────────────────────────────────────────────────────────
 * `numero_de_llave` nació esta misma mañana y se retira hoy: nombraba **uno** de los tres usos del
 * número —la llave— cuando el mismo valor identifica la casita, su puerta en el croquis y se lee
 * en su nombre. Pasa a `numero`, entero, y el `#` se cae por el camino (`'#5'` → `5`).
 *
 * Tres columnas que deben coincidir son tres oportunidades de contradecirse. El día que una
 * cerradura nueva traiga otro número grabado, eso será información NUEVA y tendrá su campo
 * entonces.
 *
 * ── Los medios ──────────────────────────────────────────────────────────────
 * `pms_unidad_media` guarda el croquis, la foto de la puerta y el vídeo del ingreso de cada
 * casita. Un tipo por casita (restricción única): el croquis de la Casita 5 es uno.
 *
 * ⚠️ **No sustituye a `pms_guia_item_galeria`, y la línea entre las dos hay que mantenerla**: aquí
 * van los medios que son un DATO —la puerta, el ingreso—, allí los que son MAQUETACIÓN —el álbum,
 * la decoración—. Un dato dentro de un ítem de guía sólo llega al modelo si el índice de temas
 * elige ese ítem, y eso ya costó que una huésped esperara una hora en la puerta (§3.b).
 *
 * ⚠️ **Las 15 imágenes que hay hoy en las galerías de los ítems «Puerta del Departamento» NO se
 * mueven aquí automáticamente.** El croquis actual numera las SIETE puertas y el plan es rehacerlo
 * uno por casita, con sólo su número; copiarlo ahora sería dar por bueno justo lo que se va a
 * cambiar. Entran a mano cuando existan los nuevos.
 *
 * `video_caja_fuerte_url` va en el establecimiento y no aquí: la caja es una para todas.
 */
final class Version20260911210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tabla de medios de la casita, `numero` entero en la unidad y el vídeo de la caja en el establecimiento.';
    }

    public function up(Schema $schema): void
    {
        // DDL copiado literal de `doctrine:schema:update --dump-sql`: los comentarios de tipo y el
        // nombre del índice los genera Doctrine, y escribirlos a mano deja el esquema «fuera de
        // sincronía» para siempre aunque las columnas sean las mismas.
        $this->addSql('CREATE TABLE pms_unidad_media (id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', unidad_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', tipo VARCHAR(30) NOT NULL, image_name VARCHAR(255) DEFAULT NULL, image_updated_at DATETIME DEFAULT NULL, url VARCHAR(255) DEFAULT NULL, orden INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', token VARCHAR(16) DEFAULT NULL, INDEX IDX_95412B519D01464C (unidad_id), UNIQUE INDEX uniq_unidad_tipo (unidad_id, tipo), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE pms_unidad_media ADD CONSTRAINT FK_95412B519D01464C FOREIGN KEY (unidad_id) REFERENCES pms_unidad (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE pms_unidad ADD numero SMALLINT DEFAULT NULL');

        // `'#5'` → `5`. `CAST` sobre el texto sin el `#`; lo que no sea un número queda a NULL,
        // que es mejor que un 0 disfrazado de casita.
        $this->addSql("UPDATE pms_unidad
                          SET numero = NULLIF(CAST(REPLACE(COALESCE(numero_de_llave, ''), '#', '') AS UNSIGNED), 0)
                        WHERE COALESCE(numero_de_llave, '') <> ''");

        $this->addSql('ALTER TABLE pms_unidad DROP numero_de_llave');

        $this->addSql('ALTER TABLE pms_establecimiento ADD video_caja_fuerte_url VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_establecimiento DROP video_caja_fuerte_url');

        $this->addSql('ALTER TABLE pms_unidad ADD numero_de_llave VARCHAR(50) DEFAULT NULL');
        $this->addSql("UPDATE pms_unidad SET numero_de_llave = CONCAT('#', numero) WHERE numero IS NOT NULL");
        $this->addSql('ALTER TABLE pms_unidad DROP numero');

        $this->addSql('DROP TABLE pms_unidad_media');
    }
}
