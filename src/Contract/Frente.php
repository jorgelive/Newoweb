<?php

declare(strict_types=1);

namespace App\Contract;

use InvalidArgumentException;

/**
 * Un asunto abierto de quien escribe: «tu reserva del 12 al 15», «tu tour del 14»,
 * «reservar alojamiento».
 *
 * ── Qué problema resuelve ───────────────────────────────────────────────────
 * El agente nació para un solo negocio, así que «de qué me hablas» nunca fue una pregunta: era
 * la reserva del huésped y punto. Con alojamiento y turismo conviviendo deja de serlo, y no
 * porque haya dos negocios —eso se resolvería mirando el `context_type`— sino porque **un mismo
 * cliente puede tener dos asuntos abiertos del MISMO negocio en momentos distintos**: una
 * estancia en curso y una cotización de ampliación pendiente. Ahí el vínculo comercial dice
 * «cliente» y no distingue nada; alguien tiene que elegir, y ese alguien es el triaje.
 *
 * ── Venta sin entidad, y es lo que lo hace simple ───────────────────────────
 * Además de los asuntos con entidad detrás, cada negocio vendible aporta un frente de VENTA
 * **sintético**, sin `entidadTipo` ni `entidadId`: la puerta que siempre está abierta. Con eso,
 * dos casos que parecían excepciones dejan de serlo y pasan a ser la misma regla:
 *
 *   - un cliente de tours que escribe «no tengo alojamiento, ¿qué ofreces?»
 *   - un huésped alojado que quiere quedarse dos noches más
 *
 * Los dos eligen el frente de venta del negocio por el que preguntan, tengan o no algo ahí.
 *
 * ── Es contexto, NUNCA permiso ──────────────────────────────────────────────
 * ⚠️ Elegir un frente cambia de qué se habla, con qué voz y de dónde salen los hitos. **No
 * amplía lo que quien pregunta puede hacer**: el catálogo de skills se congela antes, con los
 * roles y {@see \App\Agent\Access\ActorInterface::dominios()}. Si el triaje se equivoca de
 * frente, la consecuencia es una respuesta con el tono equivocado, jamás una fuga. Esa frontera
 * es el diseño y no un detalle: es la lección de la interesada de Airbnb a la que se trató como
 * huésped confirmado.
 */
final readonly class Frente
{
    /**
     * @param string $negocio    `hotelero` | `turistico`. La misma clave que usa
     *                           {@see \App\Agent\Access\ActorInterface::dominios()}.
     * @param string $etiqueta   Legible y decible al cliente: «Tu reserva Casita 3, 12–15 mar».
     *                           La escribe el dominio, que es quien sabe qué se puede enseñar.
     * @param bool   $porDefecto El que se toma cuando el mensaje no aclara de cuál habla.
     */
    public function __construct(
        public string $negocio,
        public MomentoDeFrente $momento,
        public string $etiqueta,
        public ?string $entidadTipo = null,
        public ?string $entidadId = null,
        public bool $porDefecto = false,
        /**
         * De dónde vino este asunto, ya redactado por el dominio. `null` cuando no cambia nada.
         *
         * ⚠️ El núcleo NO lo interpreta: lo transporta y lo imprime. Que Airbnb ya haya cobrado
         * o que Booking aplique depósito es conocimiento de alojamiento, y en Turismo será otro
         * —quién gestiona un cambio de fecha, quién emite el comprobante—. Lo redacta quien sabe:
         * {@see \App\Message\Contract\ConversacionEnlaceInterface::procedenciaParaElPrompt()}.
         *
         * Viaja en el frente y no en una línea suelta del contexto porque **es del asunto**: una
         * persona puede tener una estancia de Booking y un tour directo en el mismo hilo, y una
         * procedencia global sería falsa en cuanto haya dos.
         */
        public ?string $procedencia = null,
    ) {
        // Tipo y id van juntos o no van. Un frente con tipo y sin id se cuela por dos sitios a
        // la vez: `esVentaSintetica()` lo daría por la puerta de venta —mira sólo el id— y su
        // `id()` **colisionaría con la puerta real del mismo negocio**, porque el hash no
        // tendría nada que lo distinga. Entonces `porId()` devolvería el primero de la lista y
        // elegir «reservar alojamiento» acabaría hablando de una reserva concreta.
        if (($this->entidadTipo === null) !== ($this->entidadId === null)) {
            throw new InvalidArgumentException(
                'Un Frente lleva entidadTipo y entidadId juntos, o ninguno de los dos.'
            );
        }
    }

    /**
     * Identificador corto y estable que se le enseña al modelo.
     *
     * **Determinista y no posicional a propósito.** Con ids por posición (`f1`, `f2`), el mismo
     * asunto cambiaba de número en cuanto la lista se reordenaba, y una pregunta de
     * desambiguación hecha ayer dejaba de ser mapeable hoy: el cliente contesta «el segundo» y
     * el segundo ya es otro. Derivándolo del contenido, un frente conserva su id entre turnos.
     *
     * Es opaco a propósito: no lleva el uuid de ninguna entidad, así que enseñárselo al modelo
     * no filtra nada aunque acabe citado en una respuesta.
     */
    public function id(): string
    {
        return 'f' . substr(
            hash(
                'xxh128',
                // `entidadTipo` entra en el hash aunque hoy no haga falta: en cuanto un negocio
                // tenga dos entidades ancla, dos ids iguales de tablas distintas colisionarían.
                $this->negocio . '|' . $this->momento->value
                . '|' . ($this->entidadTipo ?? '') . '|' . ($this->entidadId ?? '')
            ),
            0,
            6
        );
    }

    /** ¿Es la puerta de venta siempre abierta, sin nada comprado detrás? */
    public function esVentaSintetica(): bool
    {
        return $this->momento === MomentoDeFrente::Venta && $this->entidadId === null;
    }

    /**
     * El mismo frente, marcado como el que se toma cuando el mensaje no aclara de cuál habla.
     *
     * Existe para que quien marca el defecto no tenga que reconstruir el objeto campo a campo:
     * así estaba, y el día que `Frente` gane una propiedad, quien la añada tendría que acordarse
     * de ese sitio o **se perdería en silencio, sólo en el frente por defecto**.
     */
    public function marcadoPorDefecto(): self
    {
        return new self(
            $this->negocio,
            $this->momento,
            $this->etiqueta,
            $this->entidadTipo,
            $this->entidadId,
            porDefecto: true,
        );
    }

    /** La línea que ve el modelo. Sin uuids, sin estados internos, sin importes. */
    public function comoLinea(): string
    {
        return sprintf(
            '- %s · %s · «%s»%s%s',
            $this->id(),
            $this->negocio,
            $this->etiqueta,
            $this->porDefecto ? ' (por defecto)' : '',
            $this->procedencia === null ? '' : ' — ' . $this->procedencia
        );
    }
}
