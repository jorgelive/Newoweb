<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `itinerarioNombreSnapshot` → `itinerarioNombreInternoSnapshot`, y pasa a congelar el nombre
 * OPERATIVO de la plantilla en vez de su título.
 *
 * El campo congela cómo se llamaba la plantilla el día que se aplicó, para que siga diciendo de
 * dónde salió el servicio aunque después se renombre el suyo. Guardaba el **título** —«Excursión
 * de día completo a Paracas y la Huacachina»— y el nombre no aclaraba cuál de los dos era.
 *
 * Lo que sirve aquí es el operativo, «Full Day Paracas y Huacachina»: el campo **no está en
 * `pax_cotizacion:read`**, así que no lo ve el cliente, y quien lo lee busca la plantilla en el
 * catálogo.
 *
 * ── El dato ─────────────────────────────────────────────────────────────────
 * 79 servicios lo tienen relleno: 33 con plantilla viva —convertibles— y 46 con el literal
 * «Sin plantilla», que es un marcador y se queda como está.
 *
 * ⚠️ El join necesita convertir: `itinerario_maestro_id` es `varchar(36)` con guiones y
 * `travel_itinerario.id` es `binary(16)`. Comparados en crudo **no casa ninguno** —da 0 filas sin
 * error— que es exactamente la trampa que ya documenta `App\Api\Filter\UuidRelacionFilter`.
 *
 * ── Y se retira `#[AutoTranslate]` de los dos campos internos del servicio ───
 * Traducir a siete idiomas un nombre que sólo lee el tráfico es trabajo tirado, y contradecía el
 * docblock del propio helper que lo rellena. Esta migración no toca los idiomas ya guardados: son
 * inertes —nadie los lee— y borrarlos sería arriesgar por pulcritud.
 */
final class Version20260827200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'itinerario_nombre_snapshot → itinerario_nombre_interno_snapshot, con el nombre operativo de la plantilla.';
    }

    public function up(Schema $schema): void
    {
        // ⚠️ **El contenido se migra ANTES del rename, y con el nombre VIEJO de la columna.**
        //
        // `addSql()` no ejecuta: **encola** para el final de `up()`. `$this->connection`, en
        // cambio, va inmediato. Con el `ALTER` arriba del todo se lee natural —«renombro y luego
        // relleno»— y es exactamente al revés de lo que ocurre: el SELECT salía primero y moría
        // con `Unknown column 'itinerario_nombre_interno_snapshot'`. Pasó en el despliegue del
        // 27/08/2026, con el código ya arriba y la columna todavía sin renombrar.
        //
        // Así que primero se convierte sobre `itinerario_nombre_snapshot`, y el `CHANGE` de abajo
        // se lleva los datos ya buenos dentro.
        $filas = $this->connection->fetchAllAssociative(
            'SELECT sv.id, it.nombre_interno
               FROM cotizacion_cotservicio sv
               JOIN travel_itinerario it
                 ON HEX(it.id) = UPPER(REPLACE(sv.itinerario_maestro_id, "-", ""))
              WHERE JSON_LENGTH(sv.itinerario_nombre_snapshot) > 0
                AND it.nombre_interno IS NOT NULL
                AND it.nombre_interno <> ""'
        );

        foreach ($filas as $fila) {
            $this->connection->executeStatement(
                'UPDATE cotizacion_cotservicio SET itinerario_nombre_snapshot = ? WHERE id = ?',
                [
                    json_encode(
                        [['language' => 'es', 'content' => $fila['nombre_interno']]],
                        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                    ),
                    $fila['id'],
                ]
            );
        }

        $this->write(sprintf(
            '    <info>%d servicios pasan al nombre operativo de su plantilla; los demás conservan su marcador</info>',
            \count($filas)
        ));

        // Y ahora sí el rename, que Doctrine ejecutará al terminar este método.
        $this->addSql('ALTER TABLE cotizacion_cotservicio CHANGE itinerario_nombre_snapshot itinerario_nombre_interno_snapshot JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Se devuelve el nombre de la columna, no el contenido: el título original que había antes
        // no se guardó en ninguna parte, así que reconstruirlo sería inventarlo. Quien revierta
        // tendrá el nombre operativo bajo el nombre viejo, que es honesto y no pierde nada.
        $this->addSql('ALTER TABLE cotizacion_cotservicio CHANGE itinerario_nombre_interno_snapshot itinerario_nombre_snapshot JSON NOT NULL');
    }
}
