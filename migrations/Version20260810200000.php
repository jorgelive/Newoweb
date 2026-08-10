<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Las horas fijas de las siete «Descripción» pasan a ser placeholders.
 *
 * Los siete ítems afirmaban «Check-in: desde las 14:00 · Check-out: hasta las 10:00» en texto
 * plano, en los siete idiomas. Desde que el agente recibe `hora_check_in` y `hora_check_out`
 * como datos —salen del EVENTO y pueden ser distintos por estancia, ver docs/Mensajeria.md
 * §17.2— había **dos fuentes que podían discrepar**, y el modelo podía citar cualquiera.
 *
 * ### Por qué no se reemplazó la cadena «14:00» y ya
 *
 * Porque cada idioma la escribe a su manera, y un `REPLACE` global habría dejado la mitad sin
 * tocar:
 *
 * | | Entrada | Salida |
 * |---|---|---|
 * | es | `desde las 14:00 horas` | `hasta las 10:00 horas` |
 * | en | `from 2:00 PM` | `until 10:00 am` |
 * | pt | `a partir das 14h` | `até às 10h00` |
 * | fr | `à partir de 14h00` | `jusqu'à 10h00` |
 * | de | `ab 14:00 Uhr` | `bis 10:00 Uhr` |
 * | it | `dalle ore 14:00` | `entro le 10:00` |
 * | nl | `vanaf 14:00 uur` | `tot 10:00 uur` |
 *
 * ### El ancla son los emojis, no las palabras
 *
 * 🕒 y 🕙 preceden a la hora en **los 49 bloques de idioma** (7 ítems × 7 idiomas), verificado
 * antes de escribir esto. Son lo único idéntico en todos, así que se busca el primer token con
 * pinta de hora después de cada emoji y se sustituye por su placeholder. Ni una palabra
 * traducida entra en el patrón.
 *
 * La otra opción era corregir sólo el español y marcar `sobreescribirTraduccion` para que el
 * traductor rehiciera los seis idiomas. Se descartó: `AutoTranslationService` **no protege los
 * placeholders** —no hay una sola mención de `{{` en todo el servicio—, así que mandar
 * `{{ check_in }}` a traducir es arriesgarse a que vuelva traducido o con las llaves rotas en
 * 42 bloques a la vez. El interpolador tolera mayúsculas y espacios
 * (`/\{\{\s*([a-z0-9_]+)\s*\}\}/i`) pero no que le cambien la palabra.
 *
 * ### Y en el campo del agente se quitan, no se ponen
 *
 * `agente_contenido` **no pasa por el interpolador**: un `{{ check_in }}` ahí llegaría literal
 * al modelo. Como ya recibe las horas como campos propios, la línea sobra y se borra.
 */
final class Version20260810200000 extends AbstractMigration
{
    private const string EMOJI_ENTRADA = "\u{1F552}";
    private const string EMOJI_SALIDA = "\u{1F559}";

    /** Hora en cualquiera de las formas que usan los siete idiomas: 14:00, 14h00, 14h, 2:00 PM. */
    private const string PATRON_HORA = '/(\d{1,2})(?:[:h]\d{2}|h)?\s*(?:AM|PM|am|pm|Uhr|uur|horas|hrs)?/u';

    public function getDescription(): string
    {
        return 'Sustituye las horas fijas de las 7 Descripción por {{ check_in }} / {{ check_out }} en los 7 idiomas.';
    }

    public function up(Schema $schema): void
    {
        $filas = $this->connection->fetchAllAssociative(
            "SELECT id, descripcion, agente_contenido
               FROM pms_guia_item
              WHERE nombre_interno LIKE 'Descripci%'
                AND descripcion IS NOT NULL"
        );

        foreach ($filas as $fila) {
            $bloques = json_decode((string) $fila['descripcion'], true);

            if (!is_array($bloques)) {
                continue;
            }

            foreach ($bloques as $i => $bloque) {
                if (!is_array($bloque) || !is_string($bloque['content'] ?? null)) {
                    continue;
                }

                $bloques[$i]['content'] = $this->conPlaceholders($bloque['content']);
            }

            $this->addSql(
                'UPDATE pms_guia_item SET descripcion = :descripcion WHERE id = :id',
                [
                    'descripcion' => json_encode($bloques, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'id' => $fila['id'],
                ]
            );

            if (is_string($fila['agente_contenido'])) {
                $this->addSql(
                    'UPDATE pms_guia_item SET agente_contenido = :texto WHERE id = :id',
                    [
                        'texto' => $this->sinLineasDeHora((string) $fila['agente_contenido']),
                        'id' => $fila['id'],
                    ]
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        // No se revierte el texto: reconstruir «from 2:00 PM» o «dalle ore 14:00» exigiría
        // guardar aquí las siete redacciones originales, y ese duplicado envejece solo. Un
        // placeholder resuelto muestra la MISMA hora que había escrita, así que dejarlo puesto
        // no rompe nada; si alguien lo quiere fijo otra vez, lo escribe en el panel.
        $this->throwIrreversibleMigrationException(
            'Las horas quedan como placeholder: resuelven al mismo valor. Reescríbelas en el panel si hace falta.'
        );
    }

    /** Cambia la hora que sigue a cada emoji por su placeholder. Sólo la primera de cada uno. */
    private function conPlaceholders(string $html): string
    {
        $html = $this->sustituirTrasEmoji($html, self::EMOJI_ENTRADA, '{{ check_in }}');

        return $this->sustituirTrasEmoji($html, self::EMOJI_SALIDA, '{{ check_out }}');
    }

    private function sustituirTrasEmoji(string $html, string $emoji, string $placeholder): string
    {
        $pos = mb_strpos($html, $emoji);

        if ($pos === false) {
            return $html;
        }

        // Se acota la ventana a lo que sigue al emoji hasta el fin del párrafo. Sin límite, un
        // ítem sin hora tras el emoji se llevaría por delante el primer número de más abajo —una
        // capacidad, un número de habitación—.
        //
        // ⚠️ NO se corta en el primer «<»: la hora viene envuelta en negrita
        // (`desde las <strong>14:00 horas </strong>`), así que cortar ahí dejaba la ventana en
        // un espacio y no se sustituía nada. Es el fallo que tuvo la primera versión de esto.
        $desde = $pos + mb_strlen($emoji);
        $cabeza = mb_substr($html, 0, $desde);
        $resto = mb_substr($html, $desde);

        $fin = mb_strpos($resto, '</p>');
        $salto = mb_strpos($resto, '<br');
        $corte = min(
            $fin === false ? PHP_INT_MAX : $fin,
            $salto === false ? PHP_INT_MAX : $salto,
            mb_strlen($resto)
        );

        $ventana = mb_substr($resto, 0, $corte);
        $cola = mb_substr($resto, $corte);

        $ventana = preg_replace(self::PATRON_HORA, $placeholder, $ventana, 1) ?? $ventana;

        return $cabeza . $ventana . $cola;
    }

    /** Quita del texto del agente las dos líneas de horas: ya las recibe como campos. */
    private function sinLineasDeHora(string $texto): string
    {
        $limpio = preg_replace(
            '/^.*(?:' . self::EMOJI_ENTRADA . '|' . self::EMOJI_SALIDA . ').*$\R?/mu',
            '',
            $texto
        ) ?? $texto;

        return trim((string) preg_replace('/\n{3,}/', "\n\n", $limpio));
    }
}
