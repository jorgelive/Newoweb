<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Siembra `agente_contenido` con el cuerpo actual de cada ítem, en español y en texto plano.
 *
 * El campo nació vacío ({@see Version20260810120000}) y vacío significa «usa el cuerpo
 * publicado», que es el comportamiento de siempre. Esto lo rellena para que el equipo pueda
 * **revisar tema por tema** y recortar lo que por chat no conviene decir, en vez de tener que
 * escribir 28 textos desde cero.
 *
 * ### ⚠️ Los ítems con `{{ placeholders }}` se quedan FUERA, y no es por prudencia
 *
 * El override **no pasa por el interpolador**: `consultar_guia` lo devuelve tal cual. Hornear
 * aquí un cuerpo con placeholders rompe dos cosas distintas, y una es peor que la otra:
 *
 * | Ítem | Placeholder | Qué pasaría si se sembrara |
 * |---|---|---|
 * | Llaves (general) | `{{ keybox_main }}` | El modelo leería el literal `{{ keybox_main }}`. Y resolverlo aquí sería peor: congelaría una credencial que hoy sólo sale dentro de la ventana de acceso |
 * | Wifi (general) | `{{ wifi_data }}` | Se pierde la remisión a `consultar_wifi`, que es de donde sale la contraseña buena |
 * | Ubicación (general) | `{{ map: … }}` | Bloque de maquetación; el chat no pinta mapas |
 * | Early check in Late Check Out | `{{ check_in }}` | **La hora cambia por reserva.** Congelada, todos los huéspedes recibirían la misma |
 *
 * Ese último es el que mejor explica la regla: un placeholder no es adorno, es un dato que se
 * resuelve por huésped. Con `agente_contenido` vacío esos cuatro siguen por el camino vivo, con
 * su interpolación y su ventana de acceso intactas.
 *
 * ### Qué texto se guarda
 *
 * El español, sin HTML. Se conservan los saltos de párrafo en vez de aplanarlo todo a una línea
 * —que es lo que hace `ConsultarGuiaSkill::aTextoPlano()`— porque este texto lo va a **leer y
 * editar una persona** en el panel, y un párrafo de 900 caracteres sin un solo salto no se
 * revisa: se aprueba a ojo.
 *
 * 🔎 Nueve de los 28 pasan de 1.400 caracteres de HTML y quedarán cerca o por encima del
 * recorte de 900 de la skill. Es justo el trabajo que esta siembra habilita: al revisarlos,
 * lo que sobre se borra.
 *
 * Sólo rellena los que están a `NULL`: si alguien ya escribió su versión, no se toca.
 */
final class Version20260810140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rellena agente_contenido con el cuerpo en español de cada ítem, salvo los que usan placeholders.';
    }

    public function up(Schema $schema): void
    {
        $filas = $this->connection->fetchAllAssociative(
            "SELECT id, descripcion
               FROM pms_guia_item
              WHERE agente_contenido IS NULL
                AND descripcion IS NOT NULL
                AND JSON_LENGTH(descripcion) > 0
                AND descripcion NOT LIKE '%{{%'"
        );

        $sembrados = 0;

        foreach ($filas as $fila) {
            $texto = $this->aTextoLegible($this->enEspanol((string) $fila['descripcion']));

            if ($texto === '') {
                continue;
            }

            $this->addSql(
                'UPDATE pms_guia_item SET agente_contenido = :texto WHERE id = :id',
                ['texto' => $texto, 'id' => $fila['id']]
            );

            ++$sembrados;
        }

        $this->write(sprintf('  Sembrados %d ítems; los que usan placeholders se dejan al camino vivo.', $sembrados));
    }

    public function down(Schema $schema): void
    {
        // Se vacía entero: distinguir lo sembrado de lo escrito a mano exigiría guardar de cuál
        // venía cada uno, y este campo no merece una columna de procedencia. Revertir es volver
        // al comportamiento anterior —el cuerpo publicado—, que nunca es incorrecto.
        $this->addSql('UPDATE pms_guia_item SET agente_contenido = NULL');
    }

    /** El contenido español de un campo i18n `[{"language":"es","content":"…"}]`. */
    private function enEspanol(string $json): string
    {
        $datos = json_decode($json, true);

        if (!is_array($datos)) {
            return '';
        }

        foreach ($datos as $entrada) {
            if (is_array($entrada) && strtolower((string) ($entrada['language'] ?? '')) === 'es') {
                return (string) ($entrada['content'] ?? '');
            }
        }

        return '';
    }

    /**
     * HTML → texto plano CONSERVANDO los párrafos.
     *
     * Es `ConsultarGuiaSkill::aTextoPlano()` con una diferencia deliberada: aquella colapsa
     * todos los espacios —incluidos los saltos— para gastar menos tokens, y aquí interesa lo
     * contrario, que se pueda leer en un textarea. Los saltos no le molestan al modelo.
     */
    private function aTextoLegible(string $html): string
    {
        // Cierres de bloque y <br> → salto. Antes de quitar etiquetas, o `<p>a</p><p>b</p>`
        // acabaría como «ab».
        $texto = preg_replace('#</(p|div|li|h[1-6]|tr)>#i', "\n", $html) ?? $html;
        $texto = str_replace(['<br>', '<br/>', '<br />'], "\n", $texto);

        // Las viñetas se pierden al quitar las etiquetas; se marcan antes para que una lista
        // siga leyéndose como una lista.
        $texto = preg_replace('#<li[^>]*>#i', '- ', $texto) ?? $texto;

        $texto = html_entity_decode(strip_tags($texto), ENT_QUOTES | ENT_HTML5);

        // Espacios horizontales colapsados, saltos respetados, y nunca más de una línea vacía.
        $texto = preg_replace('/[ \t\x{00A0}]+/u', ' ', $texto) ?? $texto;
        $texto = preg_replace('/ *\n */u', "\n", $texto) ?? $texto;
        $texto = preg_replace('/\n{3,}/', "\n\n", $texto) ?? $texto;

        return trim($texto);
    }
}
