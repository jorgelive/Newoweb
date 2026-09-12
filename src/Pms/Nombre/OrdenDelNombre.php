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
    /**
     * ¿La CAJA de este texto no dice nada y se puede reescribir?
     *
     * 🔥 **Sólo cuando viene TODO en mayúsculas o TODO en minúsculas.** Un canal que manda
     * «JOSE ANTONIO ALVAREZ» no está afirmando nada sobre cómo se escribe ese nombre: está
     * gritando porque su formulario lo guardó así. Pero uno que manda «Jose Antonio Alvarez» sí
     * afirma algo —alguien lo tecleó— y reescribirlo sería **corregirle el nombre a una persona
     * porque a nosotros nos parece que le falta una tilde**.
     *
     * Es la misma asimetría de todo este módulo: dejarlo quieto no cuesta nada.
     */
    /**
     * Cómo queda el par tras aplicar el veredicto entero: orden **y** caja.
     *
     * 🔥 **Aquí y sólo aquí, porque el handler y el comando llegaron a hacer cosas distintas.** El
     * handler aplicaba las dos y el comando sólo el orden, así que `--aplicar` dejaba los nombres
     * gritando y la reserva nueva no. Dos caminos que escriben el mismo dato tienen que decidir en
     * el mismo sitio, o el que se quede corto lo hace en silencio.
     *
     * Las dos decisiones tienen **varas distintas y es a propósito**: cruzar los campos le cambia
     * el nombre a una persona y exige confianza alta; recapitalizar no cambia quién es nadie.
     *
     * @param array{invertido: bool, confianza: string, nombreCapitalizado: string, apellidoCapitalizado: string} $veredicto
     * @return array{string, string}
     */
    public static function comoQuedaria(array $veredicto, string $nombre, string $apellido): array
    {
        $cruzar = $veredicto['invertido']
            && mb_strtolower(trim($veredicto['confianza'])) === self::CONFIANZA_EXIGIDA;

        // Lo capitalizado viene etiquetado por el campo del que SALIÓ, así que al cruzar hay que
        // cruzarlo también: el «nombre bien escrito» acaba en el campo del apellido.
        [$finalNombre, $finalApellido] = $cruzar ? [$apellido, $nombre] : [$nombre, $apellido];
        [$propNombre, $propApellido] = $cruzar
            ? [$veredicto['apellidoCapitalizado'], $veredicto['nombreCapitalizado']]
            : [$veredicto['nombreCapitalizado'], $veredicto['apellidoCapitalizado']];

        return [
            self::conLaCajaBuena($finalNombre, $propNombre),
            self::conLaCajaBuena($finalApellido, $propApellido),
        ];
    }

    /**
     * El texto bien escrito, si procede.
     *
     * ⚠️ **Se comprueba que sean las MISMAS letras.** Al modelo se le pidió que no tradujera ni
     * añadiera nombres, pero una petición no es un cierre —es la regla de este proyecto para todo
     * lo que decide un modelo—. Si difiere en algo más que caja y tildes, se descarta.
     *
     * De rebote, eso protege un caso real: `B0UZA` lleva un CERO donde va una O. El modelo
     * devolvería «Bouza», que se rechaza — es un dedazo del canal, no un problema de caja, y
     * arreglarlo sería que el sistema decidiera por su cuenta que una letra estaba mal.
     *
     * ⚠️ **Es público desde el 11/09/2026, y no por comodidad.** Lo necesita también el lector de
     * documentos de identidad ({@see \App\Cotizacion\Documento\LectorDeDocumentoIdentidad}): un
     * pasaporte imprime «DIAZ ARREDONDO» en mayúscula, la app muestra nombres capitalizados, y la
     * caja la propone el mismo modelo que ya está leyendo el documento.
     *
     * Ahí el guardián vale MÁS que en el PMS, no menos: sobre un escaneo el modelo tiene que
     * inventarse menos, pero el OCR se equivoca más —un `0` por una `O`, un `1` por una `I`— y
     * esto es justo lo que impide que una «corrección» de caja arrastre una letra cambiada.
     */
    public static function conLaCajaBuena(string $original, string $propuesto): string
    {
        if (trim($propuesto) === '' || !self::mereceCapitalizacion($original)) {
            return $original;
        }

        static $translit = null;
        $translit ??= \Transliterator::create('Any-Latin; Latin-ASCII; Upper');

        $plano = static function (string $t) use ($translit): string {
            $limpio = $translit?->transliterate($t) ?: mb_strtoupper($t);

            return (string) preg_replace('/[^A-Z0-9]/', '', $limpio);
        };

        return $plano($original) === $plano($propuesto) ? trim($propuesto) : $original;
    }

    public static function mereceCapitalizacion(?string $texto): bool
    {
        $t = trim((string) $texto);

        if ($t === '' || preg_match('/\p{L}/u', $t) !== 1) {
            return false;
        }

        $tieneMinuscula = preg_match('/\p{Ll}/u', $t) === 1;
        $tieneMayuscula = preg_match('/\p{Lu}/u', $t) === 1;

        return $tieneMinuscula !== $tieneMayuscula;   // todo de una caja: la caja no informa
    }

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
    /**
     * ⚠️ **De qué depende que esto no entre en bucle, y no está en este archivo.**
     *
     * `esNuestroIntercambio()` corta la realimentación de NUESTRO guardado, pero no la del
     * canal: si un pull volviera a escribir el par cruzado, el listener lo vería como un cambio
     * ajeno y preguntaría otra vez. Una llamada al modelo por pull, indefinidamente.
     *
     * Hoy no ocurre porque `BookingPullPersister` cierra `datosLocked` **en el mismo flush** en
     * que escribe el nombre, y su condición (`hasStrongContactData`) es cierta en cuanto hay
     * apellido — que es justo lo que `mereceRevision()` exige. Toda reserva revisable está ya
     * bloqueada. Medido el 31/08/2026: 326 de 329 con apellido tienen el candado cerrado, y las
     * 3 abiertas son directas (dos son bloqueos), que no pasan por el pull.
     *
     * Es una dependencia real entre dos archivos que no se citaban. Si alguien endurece esa
     * condición, este mecanismo hay que revisarlo.
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
