<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El vídeo de la caja de llaves deja de estar copiado dentro del ítem y pasa a su campo.
 *
 * ── Lo que había ───────────────────────────────────────────────────────────
 * El ítem «Llaves (general)» llevaba el bloque escrito a mano:
 *
 *     {{ video:https://youtu.be/t0adVANXkZU }}
 *
 * **en los siete idiomas**. Siete copias de la misma URL: el día que el vídeo se rehaga hay que
 * acordarse de los siete, y el que se olvide seguirá enseñando el viejo sin que nada avise. Es el
 * mismo patrón que §3.b retira para el croquis.
 *
 * `pms_establecimiento.video_caja_fuerte_url` existe desde el 11/09/2026 y estaba **vacío**,
 * porque no había dónde escribirlo: el campo no salía en el panel. Así que la guía podía pedir
 * `{{ video_caja_fuerte }}` y siempre resolvía a nada. Aquí se rellena con la URL que ya se estaba
 * publicando y se sustituyen las siete copias por el marcador.
 *
 * ── Por qué el huésped no nota nada ────────────────────────────────────────
 * El ítem ya es «Sólo en ventana (30 h)», y `video_caja_fuerte` se declara `SoloVentana`. Antes lo
 * protegía el candado del ítem; ahora, además, el del propio medio. Mismo vídeo, mismo momento.
 *
 * ⚠️ **La URL se busca, no se asume.** Sólo se toca la fila si el bloque está donde se cree que
 * está: si alguien ya lo cambió a mano, esta migración no pisa su trabajo — cuenta 0 filas y la
 * sustitución no encuentra nada que sustituir.
 */
final class Version20260912190000 extends AbstractMigration
{
    private const URL = 'https://youtu.be/t0adVANXkZU';

    public function getDescription(): string
    {
        return 'Mueve el vídeo de la caja de llaves del cuerpo del ítem (×7 idiomas) al establecimiento.';
    }

    public function up(Schema $schema): void
    {
        // 1. El dato, a su sitio. Sólo donde está vacío: si alguien ya lo escribió por el panel,
        //    lo suyo manda.
        $this->addSql(
            "UPDATE pms_establecimiento
                SET video_caja_fuerte_url = :url
              WHERE COALESCE(video_caja_fuerte_url, '') = ''",
            ['url' => self::URL]
        );

        // 2. Las siete copias, fuera. `descripcion` es un JSON con una entrada por idioma y el
        //    bloque es idéntico en todas, así que una sustitución de texto sobre la columna entera
        //    las alcanza de una vez sin tener que recorrer el JSON idioma a idioma.
        $this->addSql(
            "UPDATE pms_guia_item
                SET descripcion = REPLACE(descripcion, :viejo, :nuevo)
              WHERE nombre_interno = 'Llaves (general)'
                AND descripcion LIKE :patron",
            [
                'viejo' => '{{ video:' . self::URL . ' }}',
                'nuevo' => '{{ video_caja_fuerte }}',
                'patron' => '%{{ video:' . self::URL . ' }}%',
            ]
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "UPDATE pms_guia_item
                SET descripcion = REPLACE(descripcion, :marcador, :bloque)
              WHERE nombre_interno = 'Llaves (general)'",
            [
                'marcador' => '{{ video_caja_fuerte }}',
                'bloque' => '{{ video:' . self::URL . ' }}',
            ]
        );

        $this->addSql(
            "UPDATE pms_establecimiento SET video_caja_fuerte_url = NULL WHERE video_caja_fuerte_url = :url",
            ['url' => self::URL]
        );
    }
}
