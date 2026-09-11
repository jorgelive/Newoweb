<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El número de la llave deja de llamarse «código de puerta».
 *
 * 🔥 `pms_unidad.codigo_puerta` guardaba `#1`…`#7`, uno por casita, y **no es el código de
 * ninguna puerta**: es el número grabado en la llave que se saca de la caja fuerte. Las puertas
 * no se numeran, por seguridad — para encontrarlas está el ítem «Puerta del Departamento» de cada
 * casita, que las describe («la segunda puerta verde, al pie de las gradas»).
 *
 * El nombre bastó para que el agente le escribiera a un huésped, el 11/09/2026, «el código de la
 * caja de seguridad es 4074E y el de la puerta es #5», mandándole a buscar un número que no existe
 * en ninguna puerta. El dato era correcto; la etiqueta, no. Tuvo que corregirlo una persona: «La
 * llave es la número 5».
 *
 * ── Qué se mueve y qué se queda ─────────────────────────────────────────────
 * | columna | antes | después |
 * |---|---|---|
 * | `numero_de_llave` | no existía | `#1`…`#7` |
 * | `codigo_puerta` | `#1`…`#7` | **NULL**, reservada para el smart lock |
 *
 * `codigo_puerta` **sobrevive vacía a propósito**: es el código de la cerradura inteligente, que
 * se instalará más adelante. No es deuda: es un campo que todavía no tiene nada que guardar.
 *
 * ── El marcador de la guía ──────────────────────────────────────────────────
 * Un ítem —«Obtener las llaves desde la caja fuerte»— escribe «Toma la Llave `{{ door_code }}` del
 * interior». El texto ya decía lo correcto; el equivocado era el marcador, así que pasa a
 * `{{ numero_llave }}` en **los siete idiomas a la vez**.
 *
 * ⚠️ **Va por SQL aunque `descripcion` sea un campo traducido**, que es la excepción a la regla de
 * `CLAUDE.md`: no se cambia el contenido, se renombra un token idéntico en todos los idiomas. Por
 * el ORM, `AutoTranslationEventListener` volvería a traducir siete textos HTML largos para
 * sustituir doce caracteres, y una traducción nueva puede mover el marcador de sitio o romperlo.
 *
 * Sin alias: `{{ door_code }}` deja de resolver a esto hoy mismo. Es deliberado — un alias
 * sobreviviría al smart lock y entonces habría dos marcadores para dos cosas distintas con el
 * mismo nombre.
 */
final class Version20260911180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Separa el número de la llave del código de puerta (smart lock), y renombra su marcador en la guía.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_unidad ADD numero_de_llave VARCHAR(50) DEFAULT NULL');

        $this->addSql("UPDATE pms_unidad
                          SET numero_de_llave = codigo_puerta,
                              codigo_puerta   = NULL
                        WHERE COALESCE(codigo_puerta, '') <> ''");

        $this->addSql("UPDATE pms_guia_item
                          SET descripcion = CAST(REPLACE(CAST(descripcion AS CHAR), '{{ door_code }}', '{{ numero_llave }}') AS JSON)
                        WHERE CAST(descripcion AS CHAR) LIKE '%{{ door_code }}%'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE pms_guia_item
                          SET descripcion = CAST(REPLACE(CAST(descripcion AS CHAR), '{{ numero_llave }}', '{{ door_code }}') AS JSON)
                        WHERE CAST(descripcion AS CHAR) LIKE '%{{ numero_llave }}%'");

        $this->addSql("UPDATE pms_unidad
                          SET codigo_puerta = numero_de_llave
                        WHERE COALESCE(numero_de_llave, '') <> ''");

        $this->addSql('ALTER TABLE pms_unidad DROP numero_de_llave');
    }
}
