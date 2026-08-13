<?php

declare(strict_types=1);

namespace App\Message\Contract;

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
    ) {}

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
            hash('xxh128', $this->negocio . '|' . $this->momento->value . '|' . ($this->entidadId ?? '')),
            0,
            6
        );
    }

    /** ¿Es la puerta de venta siempre abierta, sin nada comprado detrás? */
    public function esVentaSintetica(): bool
    {
        return $this->momento === MomentoDeFrente::Venta && $this->entidadId === null;
    }

    /** La línea que ve el modelo. Sin uuids, sin estados internos, sin importes. */
    public function comoLinea(): string
    {
        return sprintf(
            '- %s · %s · «%s»%s',
            $this->id(),
            $this->negocio,
            $this->etiqueta,
            $this->porDefecto ? ' (por defecto)' : ''
        );
    }
}
