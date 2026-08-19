<?php

declare(strict_types=1);

namespace App\Pms\Nombre;

/**
 * Las decisiones puras de la revisión de nombre y apellido: a quién se revisa, si lo que
 * contestó el modelo se puede aplicar, y cómo no entrar en bucle.
 *
 * ### Por qué el modelo NO devuelve el nombre
 *
 * Contesta `invertido: true|false` y nada más; **el intercambio lo hace el código**, con las
 * cadenas que ya estaban en la reserva. Un modelo que devolviera el nombre podría escribir uno
 * que nadie tecleó —cambiar una letra, «arreglar» un apellido raro, traducirlo— y eso acabaría
 * en el saludo de un huésped sin que nadie lo revisara. Con un booleano, el peor fallo posible
 * es un intercambio equivocado de dos cadenas que ya existían. Ver CLAUDE.md, «lo que decide el
 * modelo, valídalo con código».
 *
 * ### Por qué el caso existe
 *
 * Booking manda a veces los campos cruzados. Real, del 19/08/2026 (reserva `88233049`):
 *
 * ```
 * firstName: "RODRIGUEZ BARRERA"     ← los apellidos
 * lastName:  "ALISSON ANGELICA"      ← los nombres
 * ```
 *
 * La bienvenida saludaba por el apellido. No lo arregla la capitalización
 * ({@see \App\Service\Nombre\NombreSanitizer}) y no hay regla determinista que lo resuelva: qué
 * token es nombre y cuál apellido depende de la cultura, y en muchos países el orden invertido
 * es el correcto. Es justo el tipo de juicio en el que un modelo es bueno.
 */
final readonly class OrdenDelNombre
{
    /** Confianza mínima para tocar el dato. Por debajo, se deja como vino y se registra. */
    public const string CONFIANZA_EXIGIDA = 'alta';

    /**
     * Marcadores que pone el propio pull cuando todavía no hay datos de verdad.
     *
     * Preguntarle al modelo por «Pendiente Sync (Grupo)» es pagar una llamada para que conteste
     * que no sabe. Ver `BookingPullPersister::upsert()`.
     */
    private const array RELLENOS = ['pendiente sync', '(grupo)', 'grupo'];

    /**
     * ¿Merece la pena preguntar por este par?
     *
     * Hacen falta las dos partes y con algo de letra: sin apellido no hay orden que discutir, y
     * un apellido de una letra —`H`, como lo trunca Airbnb— tampoco se puede juzgar.
     */
    public static function mereceRevision(?string $nombre, ?string $apellido): bool
    {
        $n = trim((string) $nombre);
        $a = trim((string) $apellido);

        if ($n === '' || $a === '') {
            return false;
        }

        if (mb_strlen($n) < 2 || mb_strlen($a) < 2) {
            return false;
        }

        foreach ([$n, $a] as $parte) {
            if (in_array(mb_strtolower($parte), self::RELLENOS, true)) {
                return false;
            }
        }

        return preg_match('/\p{L}/u', $n) === 1 && preg_match('/\p{L}/u', $a) === 1;
    }

    /**
     * ¿El cambio que acaba de ocurrir es NUESTRO propio intercambio?
     *
     * 🔁 **Es el corta-bucles, y sin él esto se muerde la cola.** El intercambio se guarda, el
     * guardado despierta al listener, el listener vuelve a encolar la revisión… Comparar los dos
     * pares como conjunto lo corta en seco y sin gastar una segunda llamada al modelo: si el par
     * nuevo son las mismas dos cadenas cambiadas de sitio, esto lo hicimos nosotros.
     *
     * No basta con «el nombre cambió»: un operador que corrige una tilde también cambia el
     * nombre, y ése sí queremos revisarlo.
     */
    public static function esNuestroIntercambio(
        ?string $nombreAntes,
        ?string $apellidoAntes,
        ?string $nombreAhora,
        ?string $apellidoAhora
    ): bool {
        $antes = [trim((string) $nombreAntes), trim((string) $apellidoAntes)];
        $ahora = [trim((string) $nombreAhora), trim((string) $apellidoAhora)];

        if (in_array('', $antes, true) || in_array('', $ahora, true)) {
            return false;
        }

        return $antes[0] === $ahora[1] && $antes[1] === $ahora[0];
    }

    /**
     * El par a guardar, o `null` si no se toca nada.
     *
     * Se exige que el modelo diga `invertido` **y** que lo diga con confianza alta: ante la duda
     * se deja el dato como vino. Un nombre sin cruzar es un dato del canal; uno cruzado por error
     * nuestro es un dato inventado, y es peor.
     *
     * Además se comprueba que las cadenas sigan siendo las que se le enseñaron: entre la consulta
     * y la respuesta pudo entrar otro pull o un operador, y aplicar el veredicto sobre un dato
     * distinto del juzgado es aplicarlo a ciegas.
     *
     * @return array{0: string, 1: string}|null `[nombre, apellido]` ya intercambiados
     */
    public static function resultado(
        bool $invertido,
        string $confianza,
        string $nombreJuzgado,
        string $apellidoJuzgado,
        ?string $nombreActual,
        ?string $apellidoActual
    ): ?array {
        if (!$invertido || mb_strtolower(trim($confianza)) !== self::CONFIANZA_EXIGIDA) {
            return null;
        }

        if (trim((string) $nombreActual) !== trim($nombreJuzgado)
            || trim((string) $apellidoActual) !== trim($apellidoJuzgado)) {
            return null;
        }

        return [trim($apellidoJuzgado), trim($nombreJuzgado)];
    }
}
