<?php

declare(strict_types=1);

namespace App\Agent\Skill;

/**
 * Cómo se escriben en el log una llamada a skill y lo que contestó.
 *
 * ⚠️ Existe por un turno concreto. El 24/08/2026 el asistente del panel gastó **208 000 tokens
 * de entrada en ocho vueltas y no contestó nada**, llamando a la misma skill una y otra vez. El
 * log de ese turno decía ocho veces `usa la skill "consultar_padron"` y **nada más**: ni con qué
 * argumentos, ni qué le devolvió. Con eso el diagnóstico no se lee, se adivina — hubo que
 * deducirlo restando los tamaños de entrada entre vueltas.
 *
 * Va en una clase aparte y no dentro de cada adaptador porque el formato tiene que ser **el
 * mismo** en los tres proveedores: lo que se compara al diagnosticar es «Gemini llamó así y
 * Claude asá con la misma pregunta», y dos formatos distintos obligan a leer dos veces.
 *
 * Ver docs/Agent.md §5.5.
 */
final class RastroDeSkill
{
    /** Tope del JSON de argumentos. Un pegote más largo no explica mejor la llamada. */
    private const int MAX_ARGUMENTOS = 300;

    /** Claves del resultado que se listan. Con las primeras se distingue un fallo de un acierto. */
    private const int MAX_CLAVES = 8;

    /**
     * Los argumentos con los que el modelo llamó, en JSON.
     *
     * @param array<string, mixed> $entrada
     */
    public static function argumentos(array $entrada): string
    {
        if ($entrada === []) {
            return '{}';
        }

        $json = json_encode($entrada, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($json)) {
            return '(argumentos no serializables)';
        }

        return mb_strlen($json) > self::MAX_ARGUMENTOS
            ? mb_substr($json, 0, self::MAX_ARGUMENTOS).'…'
            : $json;
    }

    /**
     * El resultado por sus CLAVES y su tamaño, nunca por su contenido.
     *
     * El contenido son 131 personas con sus documentos: volcarlo al log es duplicar la base de
     * datos en un fichero de texto y meter datos personales donde no pintan nada. Las claves
     * bastan para lo que se pregunta al diagnosticar —si vino `error_de_nombre` o vino
     * `personas`—, y el tamaño es lo que explica cuánto creció la entrada de la vuelta siguiente.
     *
     * @param array<array-key, mixed> $respuesta
     */
    public static function resultado(array $respuesta): string
    {
        $json = json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $claves = array_map(strval(...), array_slice(array_keys($respuesta), 0, self::MAX_CLAVES));

        return sprintf(
            '%s · %d B',
            $claves === [] ? '(vacío)' : implode(', ', $claves),
            is_string($json) ? strlen($json) : 0,
        );
    }

    /**
     * ¿Esta respuesta es un «no pude»?
     *
     * **La convención la fija el núcleo, no el dominio:** `error` a secas —que es lo que emiten
     * los propios adaptadores cuando la skill no existe o está bloqueada— y cualquier clave que
     * empiece por `error_`, como el `error_de_nombre` del padrón. Así el motor puede contar
     * fracasos seguidos sin saber de qué dominio son ni qué significan.
     *
     * ⚠️ Un `error_de_nombre` viaja como resultado CORRECTO a propósito —lleva las opciones
     * reales para que el modelo pregunte, ver `ConsultarPadronSkill`—, así que `SkillResult::
     * esError()` dice `false` sobre él. Esto mira la forma de la respuesta, que es lo único que
     * el motor puede ver.
     *
     * @param array<array-key, mixed> $respuesta
     */
    public static function fueFallo(array $respuesta): bool
    {
        foreach (array_keys($respuesta) as $clave) {
            $clave = (string) $clave;

            if ($clave === 'error' || str_starts_with($clave, 'error_')) {
                return true;
            }
        }

        return false;
    }
}
