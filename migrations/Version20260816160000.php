<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El proveedor deja de vivir anidado en cada tarifa y sube al componente.
 *
 * ── De dónde venía ──────────────────────────────────────────────────────────
 * Herencia del sistema antiguo, que colgó el proveedor de la tarifa para admitir
 * **varios proveedores por componente**. Ese caso nunca se dio: 19 de 19 componentes con
 * proveedor tienen exactamente uno, y ninguno tiene dos títulos, dos servicios ni dos
 * valores de anonimato distintos entre sus tarifas.
 *
 * Lo que sí se dio es el coste. En el catálogo maestro un componente llega a tener 19
 * tarifas, y `travel_tarifa.proveedor_id` está en 5 de 904: nadie repite el mismo dato 19
 * veces, así que el campo quedó abandonado y con él el filtro de proveedores del editor.
 * Y en la cotización obligaba a RECONSTRUIR en la vista una identidad que la estructura
 * había partido — con sus propios fallos: el mapa de pax se indexaba por título de tarifa,
 * así que dos tarifas homónimas del mismo día colisionaban y ganaba la última.
 *
 * ── El reparto ──────────────────────────────────────────────────────────────
 * Se queda en la tarifa lo que de verdad es por línea de precio:
 *   · `proveedor_maestro_id`, `proveedor_nombre_snapshot` → «¿de quién es ESTE precio?».
 *     Sigue siendo legítimo comparar la tarifa de Cosituc contra la de un revendedor.
 *
 * Sube al componente la PRESENTACIÓN, que se muestra una sola vez:
 *   · título, url e imágenes del proveedor y de su servicio (el tipo de habitación),
 *   · y la bandera de anonimato, que además se invierte a positivo.
 *
 * ── Adición pura: aquí no se borra nada ─────────────────────────────────────
 * Las columnas viejas de `cotizacion_cottarifa` se quedan intactas; las retira
 * `Version20260816180000`, que va justo detrás. Separarlas mantiene este paso reversible
 * por sí solo: su `down()` suelta las columnas nuevas y el origen sigue donde estaba.
 *
 * ── Se copia de UNA tarifa entera, nunca campo a campo ──────────────────────
 * En el piloto no había ambigüedad —cero componentes con dos títulos, dos servicios o dos
 * valores de anonimato entre sus tarifas—, pero **en producción puede haberla**, y ahí un
 * `MAX()` por columna es una trampa: es determinista, sí, pero puede juntar el título de
 * una tarifa con la URL de otra y construir un proveedor que no corresponde a ninguna
 * empresa real. Es el «Frankenstein» contra el que avisa `PrestadorResuelto`.
 *
 * Por eso se elige UNA tarifa por componente y se copia entera. El criterio es estable
 * —primero las que identifican al proveedor contra el maestro, luego las que traen título
 * público, y a igualdad el id— para que el resultado no dependa del orden físico de las
 * filas ni cambie entre entornos.
 *
 * ⚠️ **La migración avisa por stdout de cada componente donde tuvo que elegir.** Esa lista
 * es la que hay que revisar y reasignar a mano después. Para verla ANTES de migrar:
 * `php var/auditar-proveedor-anidado.php`.
 *
 * `proveedor_visible` se siembra con la regla vieja: se veía si había título y la tarifa
 * no estaba oculta.
 */
final class Version20260816160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cotcomponente: absorbe la presentación del proveedor que vivía anidada en cada tarifa.';
    }

    /**
     * Todo va por `$this->connection` y no por `addSql()` a propósito: `addSql()` difiere
     * la ejecución al final del método, así que el ALTER no existiría todavía cuando el
     * paso de datos intenta escribir en las columnas nuevas.
     */
    public function up(Schema $schema): void
    {
        $c = $this->connection;

        $c->executeStatement('ALTER TABLE cotizacion_cotcomponente
            ADD proveedor_maestro_id VARCHAR(36) DEFAULT NULL,
            ADD proveedor_nombre_snapshot VARCHAR(150) DEFAULT NULL,
            ADD proveedor_visible TINYINT(1) DEFAULT 0 NOT NULL,
            ADD proveedor_titulo_snapshot JSON DEFAULT NULL,
            ADD proveedor_url_snapshot VARCHAR(255) DEFAULT NULL,
            ADD proveedor_imagenes_snapshot JSON DEFAULT NULL,
            ADD proveedor_servicio_maestro_id VARCHAR(36) DEFAULT NULL,
            ADD proveedor_servicio_titulo_snapshot JSON DEFAULT NULL,
            ADD proveedor_servicio_url_snapshot VARCHAR(255) DEFAULT NULL,
            ADD proveedor_servicio_imagenes_snapshot JSON DEFAULT NULL');

        // Se copia de UNA tarifa entera, nunca campo a campo entre varias. Un MAX() por
        // columna es determinista pero puede juntar el título de una tarifa con la URL de
        // otra, y eso construye un proveedor que no corresponde a ninguna empresa real
        // — el mismo «Frankenstein» contra el que avisa App\Cotizacion\Dto\PrestadorResuelto.
        //
        // Orden de preferencia, y a igualdad el id, para que el resultado no dependa del
        // orden físico de las filas: primero las que identifican al proveedor contra el
        // maestro, luego las que además traen título público.
        $filas = $c->fetchAllAssociative("
            SELECT t.*
            FROM cotizacion_cottarifa t
            JOIN (
                SELECT cotcomponente_id, MIN(orden_id) AS elegida
                FROM (
                    SELECT cotcomponente_id,
                           CONCAT(
                               CASE WHEN proveedor_maestro_id IS NULL THEN '1' ELSE '0' END,
                               CASE WHEN JSON_LENGTH(proveedor_titulo_snapshot) > 0 THEN '0' ELSE '1' END,
                               HEX(id)
                           ) AS orden_id
                    FROM cotizacion_cottarifa
                    WHERE proveedor_maestro_id IS NOT NULL
                       OR TRIM(COALESCE(proveedor_nombre_snapshot, '')) <> ''
                       OR JSON_LENGTH(proveedor_titulo_snapshot) > 0
                       OR proveedor_servicio_maestro_id IS NOT NULL
                ) x
                GROUP BY cotcomponente_id
            ) sel
              ON sel.cotcomponente_id = t.cotcomponente_id
             AND CONCAT(
                     CASE WHEN t.proveedor_maestro_id IS NULL THEN '1' ELSE '0' END,
                     CASE WHEN JSON_LENGTH(t.proveedor_titulo_snapshot) > 0 THEN '0' ELSE '1' END,
                     HEX(t.id)
                 ) = sel.elegida
        ");

        foreach ($filas as $t) {
            $c->executeStatement(
                'UPDATE cotizacion_cotcomponente SET
                    proveedor_maestro_id                 = :mid,
                    proveedor_nombre_snapshot            = :nom,
                    proveedor_url_snapshot               = :url,
                    proveedor_titulo_snapshot            = :tit,
                    proveedor_imagenes_snapshot          = :img,
                    proveedor_servicio_maestro_id        = :smid,
                    proveedor_servicio_url_snapshot      = :surl,
                    proveedor_servicio_titulo_snapshot   = :stit,
                    proveedor_servicio_imagenes_snapshot = :simg,
                    proveedor_visible                    = :vis
                 WHERE id = :cid',
                [
                    'mid'  => $t['proveedor_maestro_id'],
                    'nom'  => $t['proveedor_nombre_snapshot'],
                    'url'  => $t['proveedor_url_snapshot'],
                    'tit'  => $t['proveedor_titulo_snapshot'] ?? '[]',
                    'img'  => $t['proveedor_imagenes_snapshot'] ?? '[]',
                    'smid' => $t['proveedor_servicio_maestro_id'],
                    'surl' => $t['proveedor_servicio_url_snapshot'],
                    'stit' => $t['proveedor_servicio_titulo_snapshot'] ?? '[]',
                    'simg' => $t['proveedor_servicio_imagenes_snapshot'] ?? '[]',
                    // Regla vieja: se veía si había título y la tarifa no estaba oculta.
                    'vis'  => ((int) ($t['proveedor_oculto'] ?? 0) === 0
                        && ($t['proveedor_titulo_snapshot'] ?? '[]') !== '[]') ? 1 : 0,
                    'cid'  => $t['cotcomponente_id'],
                ]
            );
        }

        // Los json son NOT NULL en la entidad: las filas sin dato quedan en `[]`, no NULL.
        foreach ([
            'proveedor_titulo_snapshot',
            'proveedor_imagenes_snapshot',
            'proveedor_servicio_titulo_snapshot',
            'proveedor_servicio_imagenes_snapshot',
        ] as $col) {
            $c->executeStatement("UPDATE cotizacion_cotcomponente SET $col = '[]' WHERE $col IS NULL");
        }

        $c->executeStatement('ALTER TABLE cotizacion_cotcomponente
            MODIFY proveedor_titulo_snapshot JSON NOT NULL,
            MODIFY proveedor_imagenes_snapshot JSON NOT NULL,
            MODIFY proveedor_servicio_titulo_snapshot JSON NOT NULL,
            MODIFY proveedor_servicio_imagenes_snapshot JSON NOT NULL');

        $this->write(sprintf('  Proveedores subidos al componente: %d', count($filas)));

        // Deja constancia de dónde hubo que elegir, que es exactamente lo que hay que
        // revisar a mano después. Ver var/auditar-proveedor-anidado.php.
        $ambiguos = $c->fetchFirstColumn("
            SELECT LOWER(HEX(cotcomponente_id)) FROM cotizacion_cottarifa
            GROUP BY cotcomponente_id
            HAVING COUNT(DISTINCT proveedor_maestro_id) > 1
                OR COUNT(DISTINCT NULLIF(CAST(proveedor_titulo_snapshot AS CHAR), '[]')) > 1
                OR COUNT(DISTINCT proveedor_servicio_maestro_id) > 1
                OR COUNT(DISTINCT proveedor_oculto) > 1
        ");

        if ($ambiguos !== []) {
            $this->write(sprintf(
                '  ⚠️  %d componente(s) tenían proveedores DISTINTOS entre sus tarifas. Se tomó uno'
                . ' entero (nunca campos mezclados). Revisar y reasignar: %s',
                count($ambiguos),
                implode(', ', array_slice($ambiguos, 0, 20)) . (count($ambiguos) > 20 ? '…' : '')
            ));
        }
    }

    public function down(Schema $schema): void
    {
        // Las columnas de la tarifa nunca se tocaron, así que revertir es sólo soltar
        // las nuevas: el dato original sigue intacto donde estaba.
        $this->addSql('ALTER TABLE cotizacion_cotcomponente
            DROP proveedor_maestro_id,
            DROP proveedor_nombre_snapshot,
            DROP proveedor_visible,
            DROP proveedor_titulo_snapshot,
            DROP proveedor_url_snapshot,
            DROP proveedor_imagenes_snapshot,
            DROP proveedor_servicio_maestro_id,
            DROP proveedor_servicio_titulo_snapshot,
            DROP proveedor_servicio_url_snapshot,
            DROP proveedor_servicio_imagenes_snapshot');
    }
}
