<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `travel_segmento.slug`: separa el CÓDIGO del nombre del segmento, y homogeneiza los datos.
 *
 * Espejo de lo que se hizo con `travel_itinerario` (Version20260817240000). El `nombreInterno`
 * del segmento era un código (`VIS-VALLE_VIP…-CHINCHERO`); se saca a un `slug` propio y
 * `nombreInterno` pasa a ser un nombre real —el contenido español del `titulo`—, para que tenga
 * el mismo modelo que el maestro de itinerarios (slug = código, nombreInterno = nombre).
 *
 * ── Por qué va por SQL y es seguro ──────────────────────────────────────────
 * Ni `slug` ni `nombre_interno` llevan `#[AutoTranslate]` (son string planos), así que el UPDATE
 * directo no se salta ningún listener de traducción. `titulo` (i18n, con AutoTranslate) SÓLO se
 * LEE aquí, nunca se escribe: queda intacto.
 *
 * ── Orden de los pasos (importa) ────────────────────────────────────────────
 * 1) ADD slug. 2) slug ← nombre_interno (guarda el código ANTES de pisarlo). 3) nombre_interno ←
 * titulo['es']. El 3 debe ir tras el 2 o se pierde el código. El 'es' se localiza por
 * `JSON_SEARCH` sobre `$[*].language`, sin asumir que es el elemento [0].
 */
final class Version20260817280000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'travel_segmento: añade slug (código) y homogeneiza nombreInterno = titulo[es].';
    }

    public function up(Schema $schema): void
    {
        $existe = $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'travel_segmento' AND COLUMN_NAME = 'slug'
        SQL);

        if (!$existe) {
            $this->addSql('ALTER TABLE travel_segmento ADD slug VARCHAR(150) DEFAULT NULL');
        }

        // 2) El código actual (nombre_interno) se resguarda en el slug.
        $this->addSql('UPDATE travel_segmento SET slug = nombre_interno WHERE slug IS NULL');

        // 3) nombre_interno pasa a ser el título en español. Localiza el 'es' sin depender del orden.
        $this->addSql(<<<'SQL'
            UPDATE travel_segmento
            SET nombre_interno = JSON_UNQUOTE(JSON_EXTRACT(
                    titulo,
                    REPLACE(JSON_UNQUOTE(JSON_SEARCH(titulo, 'one', 'es', NULL, '$[*].language')),
                            '.language', '.content')))
            WHERE JSON_SEARCH(titulo, 'one', 'es', NULL, '$[*].language') IS NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Reversible: el código viejo se resguardó en `slug`, así que nombre_interno se reconstruye
        // desde ahí. (Se pierde el nombre real que hubiera quedado en nombre_interno, pero el estado
        // previo a la migración —código en nombre_interno— sí se restaura fielmente.)
        $this->addSql('UPDATE travel_segmento SET nombre_interno = slug WHERE slug IS NOT NULL');
        $this->addSql('ALTER TABLE travel_segmento DROP slug');
    }
}
