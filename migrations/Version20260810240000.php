<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Inserta `{{ medios_pago }}` en el ítem de pagos, para que el huésped vea las cuentas.
 *
 * Es la última pieza que faltaba del catálogo de cobro. El widget existe
 * (`pax/src/components/GuiaUnidad/MediosPagoWidget.vue`), el provider ya sirve los medios
 * filtrados por procedencia y el motor de contenido sabe pintarlos — pero **nadie había puesto
 * el placeholder en ningún ítem**, así que en la práctica no se veía en ninguna parte.
 *
 * ### Dónde se coloca, y por qué ahí
 *
 * Al final del bloque de «Pago de la estadía», ANTES del depósito de garantía. El orden importa
 * para quien lee: primero cuánto y cómo se paga, luego el depósito. Colgarlo del final del ítem
 * lo habría dejado detrás de la lista de condiciones de devolución, que es lo último que mira
 * alguien que sólo quiere el número de cuenta.
 *
 * ### Sólo en el español
 *
 * El placeholder es un marcador, no texto: el navegador lo cambia por el componente y lo que se
 * pinta dentro son los datos del catálogo, que ya vienen traducidos. Meterlo en los siete
 * idiomas sería repetir el mismo marcador siete veces con el riesgo de que el traductor
 * automático lo toque en alguno.
 *
 * ⚠️ Ojo entonces: **un huésped que lea la guía en otro idioma no verá el widget** hasta que
 * alguien añada el marcador a su bloque. Se deja así a propósito para no tocar 6 traducciones a
 * ciegas; si se decide extenderlo, va en el panel y se ve el resultado al guardar.
 */
final class Version20260810240000 extends AbstractMigration
{
    private const string ANCLA = '<p><strong>🤝 Reservas directas, Booking.com y otras plataformas:</strong>';

    public function getDescription(): string
    {
        return 'Añade {{ medios_pago }} al ítem «Pago (general)» para que el widget se vea en la guía.';
    }

    public function up(Schema $schema): void
    {
        $fila = $this->connection->fetchAssociative(
            "SELECT id, descripcion FROM pms_guia_item WHERE nombre_interno = 'Pago (general)'"
        );

        if ($fila === false) {
            $this->write('  No existe «Pago (general)» en esta base; no hay dónde ponerlo.');

            return;
        }

        $bloques = json_decode((string) $fila['descripcion'], true);

        if (!is_array($bloques)) {
            return;
        }

        $puesto = false;

        foreach ($bloques as $i => $bloque) {
            if (!is_array($bloque) || ($bloque['language'] ?? '') !== 'es') {
                continue;
            }

            $html = (string) ($bloque['content'] ?? '');

            // Idempotente: si ya está, no se duplica.
            if (str_contains($html, 'medios_pago')) {
                return;
            }

            // Antes del bloque del depósito. Si el texto se hubiera reescrito y el ancla ya no
            // estuviera, se añade al final: es preferible que el widget salga en un sitio raro
            // a que no salga y nadie se entere.
            $pos = mb_strpos($html, self::ANCLA);
            $marcador = "\n<p>{{ medios_pago }}</p>\n";

            $bloques[$i]['content'] = $pos === false
                ? $html . $marcador
                : mb_substr($html, 0, $pos) . $marcador . mb_substr($html, $pos);

            $puesto = true;
        }

        if (!$puesto) {
            return;
        }

        $this->addSql(
            'UPDATE pms_guia_item SET descripcion = :descripcion WHERE id = :id',
            [
                'descripcion' => json_encode($bloques, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'id' => $fila['id'],
            ]
        );
    }

    public function down(Schema $schema): void
    {
        $fila = $this->connection->fetchAssociative(
            "SELECT id, descripcion FROM pms_guia_item WHERE nombre_interno = 'Pago (general)'"
        );

        if ($fila === false) {
            return;
        }

        $bloques = json_decode((string) $fila['descripcion'], true);

        if (!is_array($bloques)) {
            return;
        }

        foreach ($bloques as $i => $bloque) {
            if (is_array($bloque) && is_string($bloque['content'] ?? null)) {
                $bloques[$i]['content'] = (string) preg_replace(
                    '#\s*<p>\s*\{\{\s*medios_pago\s*\}\}\s*</p>\s*#i',
                    "\n",
                    $bloque['content']
                );
            }
        }

        $this->addSql(
            'UPDATE pms_guia_item SET descripcion = :descripcion WHERE id = :id',
            [
                'descripcion' => json_encode($bloques, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'id' => $fila['id'],
            ]
        );
    }
}
