<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * «Equipaje de mano» → «Equipaje de cabina» en las tarifas de vuelo.
 *
 * Las opciones de equipaje de un vuelo son cuatro y se venden por separado:
 *
 * ```
 * Articulo personal            la mochila que va bajo el asiento
 * Equipaje de mano             ← el que se confundía con el de arriba
 * Equipaje de bodega           el facturado
 * Equipaje de mano y bodega    ←
 * ```
 *
 * «Equipaje de mano» y «artículo personal» son las dos cosas que el pasajero LLEVA CONSIGO, así
 * que el nombre no distingue lo que se está comprando: quien lee la orden no sabe si el vuelo
 * lleva el bulto de cabina o sólo el de debajo del asiento. «Cabina» sí lo dice.
 *
 * ## Lo interesante: el cliente ya lo leía bien
 *
 * El `titulo` del maestro —el que ve el cliente, con `#[AutoTranslate]`— **ya decía «equipaje de
 * cabina»**: «con articulo personal y equipaje de cabina». Cero ocurrencias de la otra forma en
 * los 7 idiomas. Lo que iba desacompasado era el nombre INTERNO y el del prestador, o sea lo que
 * leen el operador y el proveedor. Es la regla de las tres superficies de las duchas otra vez —
 * cada una coherente consigo misma y diciendo cosas distintas entre ellas.
 *
 * ## Por qué migración y no comando
 *
 * Ninguna de estas columnas lleva `#[AutoTranslate]`, y la que sí lo lleva —`titulo`— **no se
 * toca**, porque ya está bien. No hay traducción que rehacer ni listener que deba correr: son
 * nombres planos y copias congeladas de nombres planos. El caso contrario está en
 * `Version20261005010000` (Quelccaya), donde el `titulo` SÍ cambiaba y por eso hubo comando.
 *
 * ## Lo que NO se toca, a propósito
 *
 * Cuatro ocurrencias en el CONTENIDO de un segmento —`travel_segmento.contenido` y sus 3 copias
 * en `cotizacion_segmento.contenido_snapshot`— dicen esto:
 *
 * > «Aprovecha este tiempo para acomodar tu equipaje de mano con tranquilidad…»
 *
 * Eso es prosa dirigida al viajero sobre **su propio bulto**, no la categoría de tarifa que se
 * compra. En español natural «acomodar tu equipaje de cabina» suena a formulario. Además llevan
 * `#[AutoTranslate]`: si algún día se cambian, van por comando, no por aquí.
 *
 * ## Por qué `REPLACE` es seguro, y se limita solo
 *
 * `REPLACE()` distingue mayúsculas, y la frase aparece **siempre capitalizada cuando es un
 * nombre de tarifa** («Equipaje de mano») y **siempre en minúscula cuando es prosa** («tu
 * equipaje de mano»). Medido antes de escribirla: **0 ocurrencias en minúscula** en las ocho
 * columnas de abajo. O sea que aunque un día entre prosa en una de ellas, este `REPLACE` no
 * puede tocarla.
 *
 * Y es repetible: «Equipaje de cabina» no contiene «Equipaje de mano», así que una segunda
 * pasada no encuentra nada.
 *
 * Medido antes de escribirla, barriendo las **269** columnas de texto de `travel_*`,
 * `cotizacion_*`, `operacion_*`, `pms_guia*` y `message*`: **110 filas en 10 columnas** — 106 en
 * las ocho de abajo y 4 de prosa que se quedan como están. **Ninguna en una orden emitida**
 * (`operacion_orden_servicio_item.tarifa_nombre` = 0), así que no se reescribe ningún documento
 * que un proveedor ya tenga, ni se dispara una ola de divergencias.
 */
final class Version20261005234500 extends AbstractMigration
{
    /**
     * Tabla → columnas. Todas son nombres de tarifa o copias congeladas de ellos.
     *
     * @var array<string, list<string>>
     */
    private const CAMPOS = [
        // El maestro: lo que el operador elige y lo que se le escribe al prestador.
        'travel_tarifa' => ['nombre_interno', 'nombre_para_prestador'],
        // La foto que se queda en la cotización.
        'cotizacion_cottarifa' => ['nombre_interno_snapshot', 'nombre_para_proveedor_snapshot'],
        // El caché derivado del clasificador (se regenera al guardar; se corrige para que la
        // pantalla no diga una cosa y el árbol otra mientras tanto).
        'cotizacion_cotizacion' => ['clasificacion_financiera'],
        // La Biblia: las 3 filas que lo traen no tienen orden emitida.
        'operacion_servicio' => ['tarifa_nombre', 'descripcion_servicio', 'snapshot_origen'],
    ];

    public function getDescription(): string
    {
        return 'Equipaje de mano → Equipaje de cabina en las tarifas de vuelo (no en la prosa de los segmentos)';
    }

    public function up(Schema $schema): void
    {
        foreach (self::CAMPOS as $tabla => $columnas) {
            foreach ($columnas as $columna) {
                $this->addSql(sprintf(
                    "UPDATE `%s` SET `%s` = REPLACE(`%s`, 'Equipaje de mano', 'Equipaje de cabina') "
                    . "WHERE `%s` LIKE BINARY '%%Equipaje de mano%%'",
                    $tabla,
                    $columna,
                    $columna,
                    $columna
                ));
            }
        }
    }

    public function down(Schema $schema): void
    {
        // Ésta sí es reversible de verdad, al revés que la de Quelccaya: «Equipaje de cabina» no
        // existía en ninguna fila antes de esta migración —se midió—, así que todo lo que diga
        // «cabina» en estas ocho columnas lo puso este `up()`.
        foreach (self::CAMPOS as $tabla => $columnas) {
            foreach ($columnas as $columna) {
                $this->addSql(sprintf(
                    "UPDATE `%s` SET `%s` = REPLACE(`%s`, 'Equipaje de cabina', 'Equipaje de mano') "
                    . "WHERE `%s` LIKE BINARY '%%Equipaje de cabina%%'",
                    $tabla,
                    $columna,
                    $columna,
                    $columna
                ));
            }
        }
    }
}
