<?php

declare(strict_types=1);

namespace App\Message\Contract;

use App\Message\Entity\MessageConversation;

/**
 * Un ASUNTO colgado de una conversación: una reserva, un expediente de tours, un hilo suelto.
 *
 * ── Qué problema resuelve ───────────────────────────────────────────────────
 * `MessageConversation` intentaba ser dos cosas a la vez, y no puede:
 *
 * ```
 *  el hilo con una PERSONA          ← identidad: el teléfono   (WhatsappMetaReceivePersister)
 *  el expediente de un ACTIVO       ← identidad: (tipo, id)    (MessageConversationFactory)
 * ```
 *
 * Dos creadores con claves distintas escribiendo en la misma tabla. Medido en producción: 20
 * teléfonos con más de una conversación, y no son cáscaras vacías —uno tiene 247 mensajes
 * repartidos en dos hilos, otro 122 en cuatro—. El caso extremo son 7 conversaciones creadas el
 * mismo día para un titular que reservó 7 casitas. Cuando el agente le atiende, ve **una** y el
 * historial de las otras seis no existe para él.
 *
 * La salida es que cada cosa sea una: la **conversación** es de la persona, y los asuntos
 * cuelgan de ella —uno a muchos— como enlaces que cumplen este contrato.
 *
 * ── Es `MessageContextInterface`, pero persistido ───────────────────────────
 * Los mismos datos que hoy calcula un adaptador ({@see MessageContextInterface}) y se guardan
 * aplastados en el JSON `contextData` de la conversación: `vinculo`, `milestones`, `origin`,
 * `agency`, `status_tag`. **Todos son del activo, no del hilo**, y por eso hoy hace falta una
 * conversación por reserva para que cada estancia tenga su agenda de envíos.
 *
 * Al vivir aquí, `MessageRuleEngine` podrá programar por ASUNTO en vez de por conversación, que
 * es lo que hoy obliga a partir el historial de una persona en varios hilos.
 *
 * ── Y es el {@see Frente}, con identidad de verdad ──────────────────────────
 * El modelo de frentes tuvo que inventarse un id opaco por hash porque los asuntos no eran
 * filas. Cuando lo son, el frente **es** este enlace: mismo negocio, mismo momento, misma
 * etiqueta, y un id que no hay que derivar de nada.
 */
interface ConversacionEnlaceInterface
{
    /** El hilo del que cuelga. */
    public function getConversacion(): ?MessageConversation;

    /** `hotelero` | `turistico`. La misma clave que `ActorInterface::dominios()`. */
    public function getNegocio(): string;

    /**
     * `pms_reserva`, `cotizacion_file`… Se conserva —aunque el enlace ya sepa de qué tabla es—
     * porque es la clave con la que las reglas de mensajería segmentan hoy, y cambiarla de
     * forma es un trabajo aparte del de mover los datos.
     */
    public function getContextType(): string;

    public function getContextId(): string;

    /**
     * ¿Vendido o vendiéndose? Sale del estado del activo, y lo decide cada dominio: en
     * alojamiento lo dice el estado de la reserva; en un tour podría decirlo el pago.
     */
    public function getVinculo(): VinculoComercial;

    public function getMomento(): MomentoDeFrente;

    /**
     * Las fechas con las que se programan los envíos: llegada, salida, creación…
     *
     * ⚠️ **Aquí está el corazón de la cirugía.** `MessageRuleEngine` las leía de
     * `MessageConversation::getContextMilestones()`, y por eso cada activo necesitaba su propia
     * conversación. Desde que las lee de aquí ({@see \App\Message\Service\Queue\AgendaDeAsunto}),
     * una sola conversación puede tener N agendas.
     *
     * @return array<string, string> Claves de {@see ConversationMilestoneInterface}.
     */
    public function getMilestones(): array;

    /**
     * `directo`, `airbnb`, `booking`… De qué canal vino ESTE asunto.
     *
     * Es entrada directa de `MessageRule::matchesSegmentation()`: la regla «sólo a reservas
     * directas» tiene que poder decidir por asunto, porque el mismo titular puede tener una
     * reserva directa y otra de Airbnb colgando del mismo hilo.
     */
    public function getOrigen(): ?string;

    /** Agencia mayorista dueña del asunto. La otra mitad de la segmentación. */
    public function getAgencia(): ?string;

    /**
     * Cuándo se persistió el enlace. `null` significa «nació en esta misma unidad de trabajo
     * y todavía no se flusheó».
     *
     * Ese `null` es una señal que el motor de reglas usa a propósito: un asunto recién colgado
     * de una conversación VIEJA es el equivalente a la reserva de última hora —hoy eso llega
     * como `INSERT` porque cada reserva estrena conversación, pero con hilos fusionados llegará
     * como `UPDATE` y sin esta señal perdería el rescate y el mensaje de bienvenida—. Un enlace
     * ya flusheado (poblado masivo incluido) nunca la activa: por eso es «null», y no «creado
     * hace poco», lo que define asunto nuevo.
     */
    public function getCreatedAt(): ?\DateTimeImmutable;

    /**
     * Cómo se le nombra a quien escribe: «Tu reserva Casita 3, 12/03–15/03».
     *
     * La redacta el dominio, que es quien sabe qué se puede decir: la casita y las fechas sí, el
     * localizador y el saldo no. Es lo único de este enlace que puede acabar leyéndole el modelo
     * al cliente.
     */
    public function getEtiqueta(): string;

    /** El asunto en la forma que consume el triaje. */
    public function comoFrente(): Frente;
}
