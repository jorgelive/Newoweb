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
     *        por tema. Es lo que resuelve la sección de la guía: `consultar_guia` acepta una
     *        `busqueda` corta, así que con la pista el modelo se salta la vuelta de pedir el
     *        catálogo de la guía y va directo al contenido. Sin pista sigue funcionando: pide
     *        el catálogo y elige, como hasta ahora.
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
        public string $motivo = '',
        public ?string $respuesta = null,
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
            '%s%s%s — %s%s',
            $this->tipo->value,
            $this->skill !== null ? ' → ' . $this->skill : '',
            $this->pista !== null ? sprintf(' («%s»)', $this->pista) : '',
            $this->motivo !== '' ? $this->motivo : 'sin motivo',
            $this->respuesta !== null ? sprintf(' · respuesta: «%s»', $this->respuesta) : ''
        ));
    }
}
