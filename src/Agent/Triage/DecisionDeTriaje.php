<?php

declare(strict_types=1);

namespace App\Agent\Triage;

/**
 * Lo que el triaje decidió sobre un mensaje entrante.
 *
 * 🔑 **Es una recomendación, no una orden.** La skill que viene en `$skill` NO se ejecuta ni se
 * fuerza: se le sugiere al modelo del paso siguiente, que sigue viendo el catálogo entero y
 * puede llamar a otra. Es deliberado, y es la lección de los dos fallos que costaron caro en
 * este módulo: cuando hay dos que deciden y uno puede QUITAR opciones al otro, lo que sobra se
 * vuelve invisible —no aparece en ningún log— y el fallo sólo se ve semanas después, en la
 * cara del huésped. Un filtro previo puede AÑADIR, nunca QUITAR.
 *
 * Lo que sí decide de verdad es `$tipo`, y sólo para elegir CAMINO —charla barata, camino
 * largo, aviso al equipo—, nunca para tirar un mensaje.
 */
final readonly class DecisionDeTriaje
{
    /**
     * @param string|null $skill Nombre de la skill que el triaje cree que responde. Sugerencia.
     * @param string|null $pista Una o dos palabras con el TEMA, cuando la skill elegida busca
     *        por tema. Es la red del tema_id: si el triaje no pudo fijar el ítem exacto, la
     *        pista alimenta la `busqueda` corta de `consultar_guia` y el modelo se salta la
     *        vuelta de pedir el catálogo. Sin ninguna de las dos sigue funcionando: pide el
     *        catálogo y elige, como hasta ahora.
     * @param string|null $temaId El ítem EXACTO de la guía, elegido del índice global
     *        ({@see \App\Agent\Contract\IndiceDeTemasInterface}) y ya validado contra la casita del huésped. Con esto
     *        `consultar_guia(tema_id)` trae el contenido a la primera: cero vueltas de
     *        catálogo. Sigue siendo sugerencia — la skill re-filtra contra su árbol podado.
     * @param string $motivo Una línea del propio modelo explicando por qué. No se le enseña a
     *        nadie: es para el log, que es donde se audita si el triaje acierta.
     * @param string|null $respuesta Sólo cuando el tipo es «conversacion»: la contestación al
     *        huésped, escrita por el propio clasificador EN LA MISMA LLAMADA. Clasificar un
     *        «hola» y contestarlo son el mismo trabajo de leerlo; separarlos era pagar dos
     *        llamadas (triaje + charla) por lo que cabe en una. Si viene `null` con tipo
     *        «conversacion», el mensaje sigue por el camino largo: nunca se queda sin respuesta.
     */
    public function __construct(
        public TipoDeMensaje $tipo,
        public ?string $skill = null,
        public ?string $pista = null,
        public ?string $temaId = null,
        public string $motivo = '',
        public ?string $respuesta = null,
        /**
         * Resumen en una frase de lo que pide el huésped, para el equipo.
         *
         * Lo devuelve el clasificador en la MISMA llamada porque ya ha leído los mensajes:
         * sacarlo aquí evita que `ResumenConversacionService` haga una segunda pasada sobre
         * el mismo texto. Si viene `null` —el modelo no lo rellenó, o el triaje ni siquiera
         * corrió porque el autoresponder está apagado— ese servicio lo genera por su cuenta.
         */
        public ?string $resumen = null,
        /**
         * Las herramientas que PODRÍAN responder, ya validadas contra las que este actor tiene.
         *
         * ── Para qué ────────────────────────────────────────────────────────────
         * Cuando existan dos negocios, «quiénes salen mañana» dejará de tener una sola respuesta:
         * hay salidas de alojamiento y hay tours que parten. Hoy el modelo elegiría una de las dos
         * descripciones parecidas **y acertaría o no en silencio**.
         *
         * ── Por qué una lista y no una familia declarada por la skill ───────────
         * Una taxonomía hay que decidirla a priori y se rompe en los bordes —«salidas del día»
         * contra «operaciones del día»—, y una etiqueta mal puesta no la nota nadie. Preguntarle
         * al clasificador cuáles podrían responder no necesita mantenimiento: cuando aparezca una
         * skill nueva, entra en el juego sola.
         *
         * Y sale casi gratis: el triaje ya ha leído el catálogo entero para elegir `skill`. Es
         * además una pregunta **cerrada** —nombres de una lista—, que es donde mejor se porta.
         *
         * ⚠️ **Él sólo lista; decide el código.** La lista viene filtrada por los roles del actor,
         * así que a un limpiador nunca le quedan dos: la skill de tours no está en su catálogo.
         * Con dos o más, quien fuerza la pregunta de aclaración es el procesador — si esto fuera
         * una instrucción de prompt («si dudas, pregunta»), el modelo elegiría una con toda la
         * seguridad del mundo.
         *
         * @var list<string>
         */
        public array $candidatos = [],
    ) {}

    /** El triaje no pudo decidir. Lleva al camino largo, que es el de siempre. */
    public static function indeterminado(string $motivo): self
    {
        return new self(TipoDeMensaje::Indeterminado, motivo: $motivo);
    }

    /** Para el log, en una línea. La respuesta de charla no se recorta: es lo que se audita. */
    public function etiqueta(): string
    {
        return trim(sprintf(
            '%s%s%s%s — %s%s',
            $this->tipo->value,
            $this->skill !== null ? ' → ' . $this->skill : '',
            $this->temaId !== null ? sprintf(' [tema %s]', $this->temaId) : '',
            $this->pista !== null ? sprintf(' («%s»)', $this->pista) : '',
            $this->motivo !== '' ? $this->motivo : 'sin motivo',
            $this->respuesta !== null ? sprintf(' · respuesta: «%s»', $this->respuesta) : ''
        ));
    }
}
