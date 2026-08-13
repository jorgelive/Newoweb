<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La sección «Servicios» y la ficha de cocina: el esqueleto.
 *
 * ### Por qué esto va partido en dos artefactos
 *
 * Un ítem de guía se publica en **siete idiomas** y las traducciones las genera un listener de
 * Doctrine en `prePersist`/`preUpdate`. Una migración escribe SQL directo y se lo salta. Pero la
 * ESTRUCTURA —qué fichas existen, en qué sección, en qué orden— no necesita traducción y sí
 * necesita ir versionada y ordenada.
 *
 * Así que el reparto es:
 *
 * ```
 *   MIGRACIÓN (esto)              COMANDO (app:pms:guia:tanda-servicios)
 *   ─────────────────────────     ────────────────────────────────────────
 *   crea la sección               escribe títulos y cuerpos → se traducen
 *   crea las fichas, VACÍAS       recorta el bloque de las 7 descripciones
 *   crea los enlaces APAGADOS     enciende los enlaces
 *   mueve lo que ya existía
 * ```
 *
 * ⚠️ **Los enlaces nacen con `activo = 0`**, y eso no es un detalle: `PmsGuiaSeccion::getItemsApi()`
 * filtra por enlace activo, así que entre la migración y el comando **nadie ve fichas a medio
 * hacer** —ni el huésped en la app ni el agente en su índice de temas—. Si el comando falla, lo
 * único que queda son filas invisibles.
 *
 * ### Por qué los UUID van escritos a mano
 *
 * Nada de `UUID()` de MySQL ni de generarlos en el comando: así la misma ficha tiene **el mismo id
 * en local y en producción**. Hoy los ítems de televisor tienen ids distintos en cada entorno, y
 * mientras nadie los referencie da igual — pero la escalera de temas guarda el uuid del ítem en la
 * metadata de los mensajes, y cualquier migración futura que quiera apuntar a una ficha tendría
 * que hacerlo por `nombre_interno`, que es frágil: ya renombramos ítems esta misma semana.
 *
 * ### Qué se mueve y por qué
 *
 * «Calefactor» y «Limpieza» estaban en el **Manual**, que es donde se explica cómo funcionan las
 * cosas. Pero no son cosas que funcionan: son cosas que se piden. El huésped que busca «¿qué puedo
 * pedir?» no tenía sección donde mirar, y por eso los servicios acabaron repartidos donde cupieron
 * —la lavandería dentro de una prohibición, las toallas dentro de la descripción comercial—.
 */
final class Version20260813220000 extends AbstractMigration
{
    private const string SECCION_SERVICIOS = '019ffd0d-f3d7-7571-aaa2-979999a4da89';

    /** Fichas generales nuevas, que viven en la sección Servicios. */
    private const array SERVICIOS = [
        '019ffd0d-f3d7-7c49-aaa2-97999a5d9db1' => ['Lavandería (general)', 'fa-shirt', 10],
        '019ffd0d-f3d8-741e-9b01-733c795b4f08' => ['Toallas y ropa de cama (general)', 'fa-bed', 20],
        '019ffd0d-f3d8-7442-9b01-733c797737d1' => ['Tabla de planchar (general)', 'fa-temperature-arrow-up', 30],
    ];

    /** La cocina es por casita: la 5 es de inducción y una sola hornilla. */
    private const array COCINAS = [
        1 => '019ffd0d-f3d8-745e-9b01-733c79f6827f',
        2 => '019ffd0d-f3d8-747a-9b01-733c7a9156ec',
        3 => '019ffd0d-f3d8-7492-9b01-733c7af9754b',
        4 => '019ffd0d-f3d8-74aa-9b01-733c7bb62a1d',
        5 => '019ffd0d-f3d8-74c2-9b01-733c7c0a3091',
        6 => '019ffd0d-f3d8-74da-9b01-733c7ced6451',
        7 => '019ffd0d-f3d8-74f6-9b01-733c7d8b31c0',
    ];

    public function getDescription(): string
    {
        return 'Esqueleto de la sección «Servicios» y de las fichas de cocina: filas y enlaces '
            . 'apagados. Los textos los escribe app:pms:guia:tanda-servicios.';
    }

    public function up(Schema $schema): void
    {
        $bin = static fn (string $uuid): string => sprintf("UNHEX(REPLACE('%s','-',''))", $uuid);

        // ── La sección ────────────────────────────────────────────────────────
        // `titulo` es NOT NULL: se pone el español y el comando lo reescribe por el ORM para que
        // se traduzca. Sin esto la migración no puede insertar.
        $this->addSql(sprintf(
            "INSERT INTO pms_guia_seccion (id, nombre_interno, titulo, icono, tipo, es_comun, created_at)
             VALUES (%s, 'Servicios (general)', ?, 'fa-concierge-bell', 'normas', 0, NOW())",
            $bin(self::SECCION_SERVICIOS)
        ), [json_encode([['language' => 'es', 'content' => 'Servicios']], JSON_UNESCAPED_UNICODE)]);

        // Se cuelga de las SIETE guías, entre el Manual y los pagos: primero cómo funciona la
        // casa, luego qué se puede pedir, y al final el dinero y las normas.
        $this->addSql(sprintf(
            "INSERT INTO pms_guia_has_seccion (id, guia_id, seccion_id, orden, activo, created_at)
             SELECT UNHEX(REPLACE(UUID(),'-','')), g.id, %s, 3, 1, NOW() FROM pms_guia g",
            $bin(self::SECCION_SERVICIOS)
        ));

        // El wifi y los pagos bajan un puesto para dejarle sitio.
        $this->addSql(
            "UPDATE pms_guia_has_seccion gs
             JOIN pms_guia_seccion s ON s.id = gs.seccion_id
             SET gs.orden = gs.orden + 1
             WHERE s.nombre_interno IN ('Wifi general', 'Pagos y Reglamento (general)')"
        );

        // ── Las fichas nuevas, sin texto y con el enlace apagado ──────────────
        foreach (self::SERVICIOS as $uuid => [$nombre, $icono, $orden]) {
            $this->crearItem($bin($uuid), $nombre, $icono);
            $this->enlazar($bin($uuid), $bin(self::SECCION_SERVICIOS), $orden);
        }

        foreach (self::COCINAS as $casa => $uuid) {
            $this->crearItem($bin($uuid), sprintf('Cocina (casa %d)', $casa), 'fa-kitchen-set');

            // La cocina SÍ es manual: explica cómo funciona algo, no algo que se pida.
            $this->addSql(sprintf(
                "INSERT INTO pms_guia_seccion_has_item (id, seccion_id, item_id, orden, activo, created_at)
                 SELECT UNHEX(REPLACE(UUID(),'-','')), s.id, %s, 15, 0, NOW()
                 FROM pms_guia_seccion s WHERE s.nombre_interno = 'Manual (casa %d)'",
                $bin($uuid),
                $casa
            ));
        }

        // ── Lo que ya existía y estaba en el sitio equivocado ─────────────────
        // ⚠️ PRIMERO SE COLAPSA, LUEGO SE MUEVE. «Servicios» es una sección común compartida por
        // las siete guías, así que los siete enlaces del Manual —uno por casita— se convertirían
        // en siete filas idénticas y chocan con la única `uniq_seccion_item`. Se deja uno vivo
        // por ítem antes de reapuntarlo.
        $this->addSql(
            "DELETE si FROM pms_guia_seccion_has_item si
             JOIN pms_guia_item i ON i.id = si.item_id
             JOIN pms_guia_seccion s ON s.id = si.seccion_id
             JOIN (
                SELECT si2.item_id, MIN(si2.id) AS superviviente
                FROM pms_guia_seccion_has_item si2
                JOIN pms_guia_item i2 ON i2.id = si2.item_id
                JOIN pms_guia_seccion s2 ON s2.id = si2.seccion_id
                WHERE i2.nombre_interno IN ('Calefactor (general)', 'Limpieza (general)')
                  AND s2.nombre_interno LIKE 'Manual (casa %)'
                GROUP BY si2.item_id
             ) vivos ON vivos.item_id = si.item_id
             WHERE i.nombre_interno IN ('Calefactor (general)', 'Limpieza (general)')
               AND s.nombre_interno LIKE 'Manual (casa %)'
               AND si.id <> vivos.superviviente"
        );

        // Y ahora sí: se reapunta el que queda, en vez de borrar y crear. Así el ítem conserva su
        // id y todo lo que lo referencie —huellas de la escalera incluidas— sigue valiendo.
        $this->addSql(sprintf(
            "UPDATE pms_guia_seccion_has_item si
             JOIN pms_guia_item i ON i.id = si.item_id
             JOIN pms_guia_seccion s ON s.id = si.seccion_id
             SET si.seccion_id = %s, si.orden = IF(i.nombre_interno LIKE 'Calefactor%%', 40, 50)
             WHERE i.nombre_interno IN ('Calefactor (general)', 'Limpieza (general)')
               AND s.nombre_interno LIKE 'Manual (casa %%)'",
            $bin(self::SECCION_SERVICIOS)
        ));
    }

    public function down(Schema $schema): void
    {
        $bin = static fn (string $uuid): string => sprintf("UNHEX(REPLACE('%s','-',''))", $uuid);
        $ids = implode(',', array_map($bin, [...array_keys(self::SERVICIOS), ...array_values(self::COCINAS)]));

        // Calefactor y Limpieza vuelven al Manual de cada casita, uno por guía.
        $this->addSql(sprintf(
            "DELETE FROM pms_guia_seccion_has_item WHERE seccion_id = %s",
            $bin(self::SECCION_SERVICIOS)
        ));
        $this->addSql(
            "INSERT INTO pms_guia_seccion_has_item (id, seccion_id, item_id, orden, activo, created_at)
             SELECT UNHEX(REPLACE(UUID(),'-','')), s.id, i.id, 10, 1, NOW()
             FROM pms_guia_seccion s
             JOIN pms_guia_item i ON i.nombre_interno IN ('Calefactor (general)', 'Limpieza (general)')
             WHERE s.nombre_interno LIKE 'Manual (casa %)'"
        );

        $this->addSql(sprintf('DELETE FROM pms_guia_seccion_has_item WHERE item_id IN (%s)', $ids));
        $this->addSql(sprintf('DELETE FROM pms_guia_item WHERE id IN (%s)', $ids));
        $this->addSql(sprintf('DELETE FROM pms_guia_has_seccion WHERE seccion_id = %s', $bin(self::SECCION_SERVICIOS)));
        $this->addSql(sprintf('DELETE FROM pms_guia_seccion WHERE id = %s', $bin(self::SECCION_SERVICIOS)));
        $this->addSql(
            "UPDATE pms_guia_has_seccion gs
             JOIN pms_guia_seccion s ON s.id = gs.seccion_id
             SET gs.orden = gs.orden - 1
             WHERE s.nombre_interno IN ('Wifi general', 'Pagos y Reglamento (general)')"
        );
    }

    /** Una ficha vacía: título provisional en español, cuerpo nulo, y sin publicar. */
    private function crearItem(string $idSql, string $nombre, string $icono): void
    {
        $this->addSql(sprintf(
            "INSERT INTO pms_guia_item
                (id, nombre_interno, tipo, visibilidad, categoria, icono, titulo,
                 agente_requiere_humano, sobreescribir_traduccion, created_at)
             VALUES (%s, ?, 'card', 'cliente', 'general', ?, ?, 0, 0, NOW())",
            $idSql
        ), [$nombre, $icono, json_encode([['language' => 'es', 'content' => $nombre]], JSON_UNESCAPED_UNICODE)]);
    }

    private function enlazar(string $itemSql, string $seccionSql, int $orden): void
    {
        $this->addSql(sprintf(
            "INSERT INTO pms_guia_seccion_has_item (id, seccion_id, item_id, orden, activo, created_at)
             VALUES (UNHEX(REPLACE(UUID(),'-','')), %s, %s, %d, 0, NOW())",
            $seccionSql,
            $itemSql,
            $orden
        ));
    }
}
