<?php

declare(strict_types=1);

namespace App\Message\Contract;

use App\Contract\Frente;
use App\Contract\MomentoDeFrente;
use App\Contract\VinculoComercial;
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
     * @return array<string, string> Claves de {@see \App\Contract\ConversationMilestoneInterface}.
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
     * De dónde vino este asunto, **redactado para el modelo**, o `null` si no cambia nada.
     *
     * ── Por qué lo escribe el dominio y no el núcleo ────────────────────────
     * `getOrigen()` devuelve un identificador opaco —`booking`, `airbnb`, `directo`— y el núcleo
     * no sabe ni debe saber qué implica cada uno. Lo que cambia la respuesta no es la etiqueta,
     * son sus **consecuencias**: en Airbnb la plataforma ya cobró y no hay depósito; en Booking
     * se paga aquí y sí lo hay. Eso es conocimiento de alojamiento, y en Turismo será otro
     * —quién gestiona un cambio de fecha, quién emite el comprobante—.
     *
     * Así que el dominio entrega la frase hecha y el núcleo la concatena sin entenderla. Es la
     * misma división que ya usan {@see \App\Agent\Contract\InstruccionesDeDominioInterface} y
     * {@see \App\Agent\Contract\IndiceDeTemasInterface}.
     *
     * ── Va pegado al ASUNTO, no a la conversación ───────────────────────────
     * ⚠️ Y es la razón de que viva aquí. Con la fusión de hilos por contacto, una misma persona
     * puede tener una estancia de Booking y un tour directo en el mismo chat: una procedencia a
     * nivel de conversación sería falsa en cuanto hubiera dos asuntos, y elegir «el principal»
     * es exactamente el error que la separación persona/asunto vino a corregir.
     *
     * ── Qué escribir ────────────────────────────────────────────────────────
     * Una línea corta y **en positivo para las dos ramas**: lo que sí pasa, no lo que no hay que
     * decir. «Airbnb: ya cobrado, sin depósito» funciona; «no le menciones el depósito» es una
     * supresión, y las supresiones en el prompt ya se han ignorado varias veces en este módulo.
     */
    public function procedenciaParaElPrompt(): ?string;

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

    /**
     * ¿Es ÉSTE el hilo por el que se atiende el asunto?
     *
     * ── Por qué un papel y no una restricción ───────────────────────────────
     * Un asunto puede colgar legítimamente de VARIOS hilos, y el esquema ya lo permite —el
     * único índice del enlace es `(conversacion_id, reserva_id)`—. El caso real es una reserva
     * con dos huéspedes que escriben desde números distintos: son dos personas, dos hilos, y
     * fundirlos sería mentir.
     *
     * Pero la agenda de envíos **no puede duplicarse**: el motor programa por asunto, y con dos
     * agendas vivas de la misma reserva la bienvenida, el recordatorio de saldo y el check-out
     * salen dos veces. Y el aviso de pago no es para el acompañante.
     *
     * Así que la respuesta no era prohibir el segundo enlace, sino decir cuál manda:
     *
     * ```
     *  titular      → recibe la agenda automática. Es el hilo del asunto.
     *  no titular   → contexto: el agente puede contestarle sobre el asunto, pero no se le
     *                 programa nada.
     * ```
     *
     * ── Y es el camino DURADERO de resolución ───────────────────────────────
     * Es lo que permite alcanzar el hilo de una reserva sin depender de un identificador de
     * persona. Un `bookId` de Beds24 es la dirección del ASUNTO, no de nadie —hay 46 repartidos
     * en 38 hilos, y una misma persona acumula siete—, así que resolver por él tiene que ser
     * `bookId → reserva → enlace titular → hilo`, no una identidad más.
     */
    public function esTitular(): bool;

    /**
     * Por qué canales se puede alcanzar ESTE asunto. **Lista vacía = sin acotar.**
     *
     * ── Por qué cuelga del asunto y no de la conversación ───────────────────
     * Por lo mismo que {@see self::getOrigen()}: con los hilos fusionados por persona, la misma
     * conversación puede llevar una estancia de Booking —que SÍ tiene hilo en Beds24— y un
     * expediente de tours, que no lo tiene ni lo tendrá nunca. Una lista de canales «de la
     * conversación» sería falsa en cuanto hubiera dos asuntos, y elegir «el principal» es
     * justo el error que la separación persona/asunto vino a corregir.
     *
     * ── Estructural, no por registro ────────────────────────────────────────
     * ⚠️ Esto responde «¿existe este canal para este negocio?», no «¿está listo hoy este
     * registro?». Que una reserva concreta tenga `beds24_book_id` es un corte más fino y vive
     * donde ya funciona, en `Beds24SendEnqueuer::isBusinessValid()`. Mezclarlos duplicaría esa
     * comprobación en cada dominio.
     *
     * Por eso alojamiento devuelve `[]` —puede usar los tres canales, y cuál sirve para cada
     * reserva lo decide el encolador— y Turismo devuelve su lista: no es que hoy no pueda, es
     * que un expediente de viaje no tiene conversación en un channel manager de hoteles.
     *
     * @return list<string> Ids de {@see \App\Message\Entity\MessageChannel}. Opacos para
     *                      quien los consume: se intersecan, no se interpretan.
     */
    public function canalesPosibles(): array;

    /** El asunto en la forma que consume el triaje. */
    public function comoFrente(): Frente;
}
