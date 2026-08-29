<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Un vuelo es UN TRAMO: fuera el JSON de segmentos, dentro las columnas.
 *
 * ⚠️ Guardé Copa como un vuelo «CM264 / CM177» con dos segmentos, y eso no es un vuelo: es un
 * **billete**. `CM264` va de Lima a Panamá y `CM177` de Panamá a Punta Cana, con su número, su
 * avión y su horario cada uno. Lo que los une —que se compraron juntos— **es el PNR**, y eso ya
 * estaba en el puente; sobraba el nivel intermedio.
 *
 * Con un solo tramo por fila, `origen`, `destino`, `salida` y `llegada` pueden ser columnas, y
 * entonces sí se puede preguntar «¿qué sale de LIM el 23?».
 *
 * Y el grupo estrena `notas`: de la carga real de Sky salieron el plazo del portal de grupos
 * (12/09), el número de solicitud y un cambio de nombre en curso. Sin sitio donde ponerlas,
 * viven en la bandeja de correo de alguien.
 *
 * ── El traslado ─────────────────────────────────────────────────────────────
 *
 * Los vuelos existentes tienen entre uno y dos segmentos en JSON. Se rellenan las columnas con
 * el PRIMER segmento, que para los de un solo tramo es exacto; los de dos —los dos de Copa—
 * quedan con su primera pata y el importador los rehace al cargar el archivo nuevo.
 *
 * ⚠️ Irreversible: al pasar de dos segmentos a cuatro columnas se pierde la segunda pata, y
 * `down()` no puede inventarla.
 */
final class Version20260829000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Vuelo = un tramo: origen/destino/salida/llegada como columnas; notas en el grupo.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_file_grupo ADD notas JSON NOT NULL DEFAULT (JSON_ARRAY())');

        $this->addSql("ALTER TABLE cotizacion_vuelo ADD origen VARCHAR(3) NOT NULL DEFAULT ''");
        $this->addSql("ALTER TABLE cotizacion_vuelo ADD destino VARCHAR(3) NOT NULL DEFAULT ''");
        $this->addSql("ALTER TABLE cotizacion_vuelo ADD salida DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00' COMMENT '(DC2Type:datetime_immutable)'");
        $this->addSql("ALTER TABLE cotizacion_vuelo ADD llegada DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00' COMMENT '(DC2Type:datetime_immutable)'");

        // Del primer segmento del JSON a las columnas.
        $this->addSql(<<<'SQL'
            UPDATE cotizacion_vuelo
               SET origen  = COALESCE(JSON_UNQUOTE(JSON_EXTRACT(segmentos, '$[0].origen')), ''),
                   destino = COALESCE(JSON_UNQUOTE(JSON_EXTRACT(segmentos, '$[0].destino')), ''),
                   salida  = COALESCE(STR_TO_DATE(JSON_UNQUOTE(JSON_EXTRACT(segmentos, '$[0].salida')),  '%Y-%m-%d %H:%i'), salida),
                   llegada = COALESCE(STR_TO_DATE(JSON_UNQUOTE(JSON_EXTRACT(segmentos, '$[0].llegada')), '%Y-%m-%d %H:%i'), llegada)
             WHERE JSON_LENGTH(segmentos) > 0
        SQL);

        $this->addSql('ALTER TABLE cotizacion_vuelo DROP segmentos');
        $this->addSql('ALTER TABLE cotizacion_vuelo ALTER origen DROP DEFAULT');
        $this->addSql('ALTER TABLE cotizacion_vuelo ALTER destino DROP DEFAULT');
        $this->addSql('ALTER TABLE cotizacion_vuelo ALTER salida DROP DEFAULT');
        $this->addSql('ALTER TABLE cotizacion_vuelo ALTER llegada DROP DEFAULT');
        $this->addSql('ALTER TABLE cotizacion_file_grupo ALTER notas DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'De dos segmentos a cuatro columnas se pierde la segunda pata: no se puede reconstruir.',
        );
    }
}
