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
     *        ({@see \App\Message\Contract\IndiceDeTemasInterface}) y ya validado contra la casita del huésped. Con esto
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
