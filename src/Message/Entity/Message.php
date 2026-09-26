<?php

declare(strict_types=1);

namespace App\Message\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Message\ApiPlatform\State\MessageMultipartProcessor;
use App\Message\Filter\MessageVistaDelHiloExtension;
use App\Message\Validator\ValidTemplateScope;
use App\Security\Roles;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use App\Message\Contract\MessageQueueItemInterface;

/**
 * Entidad que representa un mensaje individual dentro de una conversación.
 * Expuesta a través de API Platform permitiendo lectura y escritura.
 */
#[ORM\Entity]
#[ORM\Table(name: 'msg_message')]
#[ORM\Index(columns: ['status'], name: 'idx_msg_status')]
#[ORM\Index(columns: ['direction'], name: 'idx_msg_direction')]
#[ORM\Index(columns: ['asunto_type', 'asunto_id'], name: 'idx_msg_asunto')]
// El enfriamiento del escalado consulta por (escalado_de, created_at) en CADA escalado.
#[ORM\Index(columns: ['escalado_de', 'created_at'], name: 'idx_msg_escalado_de')]
// El listado de un hilo es la consulta más caliente del panel y ordena por `ocurrio_at`:
// sin este índice, cada apertura de chat es un `filesort` sobre todo el hilo.
//
// ⚠️ **No le falta un tercer tramo `id`.** El orden real es `ocurrio_at DESC, id DESC`, así que
// parece que el desempate quedaría fuera del índice — y no: InnoDB **extiende** todo índice
// secundario con la clave primaria, de modo que para el optimizador esto ya es
// `(conversation_id, ocurrio_at, id)`. Comprobado con EXPLAIN en producción el 26/09/2026 sobre
// el hilo de 665 mensajes: `backward index scan`, 31 filas, sin `filesort`. Declarar el tercer
// tramo a mano sólo duplicaría la columna en el índice.
#[ORM\Index(columns: ['conversation_id', 'ocurrio_at'], name: 'idx_msg_hilo_ocurrio')]
#[ORM\HasLifecycleCallbacks]
#[ValidTemplateScope]
#[ApiResource(
    shortName: 'Message',    // 🔥 Define el nombre base del recurso para generar '/messages'
    operations: [
        // ------------------------------------------------------------------------
        // 1. SUBRECURSO: Obtener mensajes filtrados por una conversación específica
        // API Platform infiere combinando el prefix: GET /message/conversations/{id}/messages
        // ------------------------------------------------------------------------
        // ⚠️ UNA OPERACIÓN POR PESTAÑA, y las tres ordenan por `ocurrioAt`.
        //
        // Antes era una sola lista que el front repartía en tres. Con eso, un hilo con muchos
        // programados llenaba la página 1 de fechas de 2027 y **el historial salía vacío**
        // —medido el 17/09/2026 en el hilo de Susan: 29 cancelados y 1 programado—, y los
        // contadores mentían porque contaban lo descargado («Programados (1)» con seis en la
        // base). Se intentó arreglar ordenando por creación, y eso trajo el fallo contrario:
        // se PAGINABA por `created_at` y se PINTABA por la fecha efectiva, así que el orden
        // cambiaba según llegara el mensaje por Mercure o por pull.
        //
        // La condición de cada pestaña vive en `MessageVistaDelHiloExtension`, no en la URL:
        // pedir la operación «a pelo» no puede devolver la lista equivocada.
        //
        // El desempate por `id` NO es decorativo: 1.781 filas comparten segundo exacto dentro
        // de un mismo hilo, y sin él `LIMIT/OFFSET` puede saltarse una en el borde de página.
        // El id es UUID v7, o sea que además desempata por orden de creación.
        new GetCollection(
            uriTemplate: '/conversations/{id}/messages',
            uriVariables: [
                'id' => new Link(
                    toProperty: 'conversation',
                    fromClass: MessageConversation::class
                )
            ],
            order: ['ocurrioAt' => 'DESC', 'id' => 'DESC'],
            name: MessageVistaDelHiloExtension::HISTORIAL
        ),

        new GetCollection(
            uriTemplate: '/conversations/{id}/messages/programados',
            uriVariables: [
                'id' => new Link(
                    toProperty: 'conversation',
                    fromClass: MessageConversation::class
                )
            ],
            // Ascendente: lo primero que va a salir, primero. Es una agenda, no un historial.
            order: ['ocurrioAt' => 'ASC', 'id' => 'ASC'],
            name: MessageVistaDelHiloExtension::PROGRAMADOS
        ),

        new GetCollection(
            uriTemplate: '/conversations/{id}/messages/cancelados',
            uriVariables: [
                'id' => new Link(
                    toProperty: 'conversation',
                    fromClass: MessageConversation::class
                )
            ],
            order: ['ocurrioAt' => 'DESC', 'id' => 'DESC'],
            name: MessageVistaDelHiloExtension::CANCELADOS
        ),

        // ------------------------------------------------------------------------
        // 2. OPERACIONES ESTÁNDAR CRUD (Rutas inferidas automáticamente)
        // ------------------------------------------------------------------------

        // API Platform infiere automáticamente: GET /message/messages
        new GetCollection(),

        // Al quitar la seguridad local y la ruta, hereda el escudo global.
        // API Platform infiere automáticamente: GET /message/messages/{id}
        new Get(),

        // API Platform infiere automáticamente: POST /message/messages
        new Post(
            inputFormats: [
                'jsonld' => ['application/ld+json'],
                'multipart' => ['multipart/form-data']
            ],
            // 🔥 Verificamos ÚNICAMENTE el rol explícito de escritura (Post-Desnormalización)
            // Se ejecuta después de que el objeto ha sido construido pero antes de persistir
            securityPostDenormalize: "is_granted('" . Roles::MENSAJES_WRITE . "')",
            securityPostDenormalizeMessage: 'No tienes permiso para enviar mensajes.',
            processor: MessageMultipartProcessor::class
        )
    ], // 🔥 Define el módulo o contexto delimitado
    routePrefix: '/message',
    normalizationContext: ['groups' => ['message:read']],
    denormalizationContext: ['groups' => ['message:write']],

    // 🔥 Escudo global: Solo usuarios con el rol de lectura pueden entrar a cualquier operación GET
    security: "is_granted('" . Roles::MENSAJES_SHOW . "')",
    securityMessage: 'Acceso denegado al módulo de mensajería.'
)]
class Message
{
    use IdTrait;
    use TimestampTrait;

    public const string STATUS_FAILED    = 'failed';

    /**
     * No había por dónde mandarlo, y **no es un fallo**.
     *
     * 🔥 Existe porque `failed` mezclaba dos cosas que no piden lo mismo: «se intentó y se rompió»
     * y «ningún canal aplicaba a esta reserva». Medido el 14/09/2026: de **395** mensajes en
     * `failed`, **355** eran del segundo tipo —249 sin canal viable y 106 de reservas directas,
     * que Beds24 rechaza por diseño—. Con 395 filas en rojo un fallo de verdad no se distingue, y
     * `AvisoEnvioFallidoListener` sólo mira los del equipo: nadie iba a leerlas nunca.
     *
     * ⚠️ **No es terminal.** El mensaje sigue vivo, y lo revive `MessageRuleEngine::syncPendingMessage()`
     * cuando vuelve a haber canal —se añade el teléfono, se fusiona el hilo con el de su número,
     * se desbloquea WhatsApp—, siempre que su fecha no haya pasado.
     *
     * 🔥 **Aquí decía que lo revivía el `preUpdate`, y no era verdad hasta el 26/09/2026.** El
     * listener sólo pide colas para `pending` y `queued`, y el motor sincronizaba la fecha sin
     * tocar el estado: un `sin_canal` se quedaba así para siempre. Además, «no hay teléfono» ni
     * siquiera llegaba aquí —el encolador de WhatsApp lanzaba y caía en `failed`—. Lo destapó
     * Melanie: hilo fusionado con el de su número y la guía de llegada seguía muerta.
     *
     * Y es también lo que queda cuando la regla se queda sin ningún canal válido. Antes eso
     * CANCELABA, y como un cancelado no es el intento vigente de su regla, el motor fabricaba
     * otro en cada disparo: un bucle de crear y cancelar.
     */
    public const string STATUS_SIN_CANAL = 'sin_canal';

    /**
     * Los estados que significan «no salió», para quien espera el mensaje.
     *
     * Los leen los dos lados del aviso de envío fallido —el listener que lo detecta y el
     * manejador que lo manda—, y tienen que leer la misma lista: si uno aceptara `sin_canal` y
     * el otro no, el aviso se encolaría y se descartaría sin decir nada.
     */
    public const array ESTADOS_NO_SALIO = [self::STATUS_FAILED, self::STATUS_SIN_CANAL];
    public const string STATUS_PENDING   = 'pending';
    public const string STATUS_QUEUED    = 'queued';
    public const string STATUS_SENT      = 'sent';
    public const string STATUS_DELIVERED = 'sent';
    public const string STATUS_RECEIVED  = 'received';
    public const string STATUS_READ      = 'read';
    public const string STATUS_CANCELLED = 'cancelled';

    public const string DIRECTION_INCOMING = 'incoming';
    public const string DIRECTION_OUTGOING = 'outgoing';

    public const string SENDER_HOST     = 'host';
    public const string SENDER_GUEST    = 'guest';
    public const string SENDER_SYSTEM   = 'system';
    public const string SENDER_INTERNAL = 'internalNote';

    #[ORM\ManyToOne(targetEntity: MessageConversation::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['message:read', 'message:write'])]
    private ?MessageConversation $conversation = null;

    #[ORM\ManyToOne(targetEntity: MessageChannel::class)]
    #[ORM\JoinColumn(name: 'channel_id', referencedColumnName: 'id', nullable: true)]
    #[Groups(['message:read'])]
    private ?MessageChannel $channel = null;

    #[ORM\ManyToOne(targetEntity: MessageTemplate::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['message:read', 'message:write'])]
    private ?MessageTemplate $template = null;

    /**
     * Regla que programó este mensaje. Sólo se rellena en mensajes del sistema.
     *
     * La plantilla NO sirve como identidad: dos reglas distintas pueden compartirla
     * (recordatorio a -7 días y a -1 día), y sin esta columna el motor las confundía
     * y se pisaban la fecha de envío entre sí. Ver docs/Mensajeria.md §4.3.
     *
     * `SET NULL` al borrar la regla: el mensaje ya programado sobrevive como histórico
     * y el motor lo tratará como legado (emparejado por plantilla).
     */
    #[ORM\ManyToOne(targetEntity: MessageRule::class)]
    #[ORM\JoinColumn(name: 'rule_id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['message:read'])]
    private ?MessageRule $rule = null;

    /**
     * El ASUNTO que programó este mensaje: el par `(tipo, id)` del activo — p. ej.
     * `('pms_reserva', <uuid de la reserva>)`.
     *
     * ── Por qué hace falta ───────────────────────────────────────────────────
     * Con una conversación por reserva, «de qué asunto es este mensaje» era trivial: del único
     * que la conversación tenía. Con varios asuntos colgando del mismo hilo
     * ({@see MessageConversation::getEnlaces()}), la regla deja de bastar como identidad: la
     * MISMA regla programa legítimamente N mensajes en la misma conversación, uno por reserva.
     * Sin esto, el motor encontraba el mensaje de la reserva A al evaluar la B, le pisaba la
     * fecha, y de N recordatorios sobrevivía uno.
     *
     * ── Por qué dos strings y no una FK al enlace ────────────────────────────
     * Dos razones, y las dos son deliberadas:
     *
     * 1. Los enlaces viven en una tabla POR MÓDULO (`pms_conversacion_enlace` hoy, la de
     *    cotizaciones mañana). Una FK ataría `msg_message` al esquema del PMS, que es justo la
     *    dependencia que la cirugía está quitando; y una FK polimórfica no existe en MySQL.
     * 2. La identidad real del asunto es el par `(contextType, contextId)` — la misma con la
     *    que segmentan las reglas—. La fila del enlace es sólo su representación persistida:
     *    si un repoblado la borra y la recrea, el UUID cambia pero el asunto es el mismo, y
     *    los mensajes ya encolados tienen que seguir reconociéndose como suyos.
     *
     * ── Quién lo rellena ────────────────────────────────────────────────────
     * Lo estampa {@see \App\Message\Service\Conversacion\AsuntoDelMensaje} en el `prePersist`
     * de TODO mensaje, entrante o saliente. Hasta el 20/08/2026 sólo lo ponía el motor de
     * reglas, y por eso había 4889 mensajes con el par vacío: el entrante, el del panel y el
     * del agente nacían todos sin él.
     *
     * ── Qué significa `null` ahora ──────────────────────────────────────────
     * Significaba tres cosas a la vez y no se distinguían. Ahora el número de asuntos del hilo
     * decide, y `null` vuelve a ser una respuesta:
     *
     * ```
     * 0 asuntos   → null, y es verdad: un walk-in no tiene ninguno
     * 1 asunto    → se estampa; es determinista
     * 2 o más     → null = AMBIGUO, lo tiene que decir quien escribe
     * ```
     *
     * Los mensajes anteriores conservan su `null` de legado: el motor los trata como del asunto
     * propio de la conversación y los adopta la primera vez que los vuelve a tocar.
     */
    #[ORM\Column(name: 'asunto_type', type: 'string', length: 50, nullable: true)]
    #[Groups(['message:read', 'message:write'])]
    private ?string $asuntoType = null;

    /** La otra mitad del par. Ver {@see self::$asuntoType}. */
    #[ORM\Column(name: 'asunto_id', type: 'string', length: 100, nullable: true)]
    #[Groups(['message:read', 'message:write'])]
    private ?string $asuntoId = null;

    /**
     * Si este mensaje es un AVISO a la guardia, de qué conversación se escaló.
     *
     * ## Por qué una columna y no la metadata
     *
     * El dato ya estaba —en `metadata['escalado_de']`— pero JSON no se puede consultar sin atar
     * el código a la versión de MySQL, así que {@see \App\Agent\Skill\Pms\EscalarAlEquipoSkill}
     * traía **las 100 filas más recientes de las conversaciones `staff`** y filtraba en PHP.
     *
     * Esa cota por `contextType` es el problema: al fusionar el hilo de alguien del equipo que
     * además es huésped, sus avisos dejan de encontrarse y **el enfriamiento falla abierto — la
     * guardia vuelve a sonar entera, de noche y sin causa evidente**.
     *
     * Con la columna la consulta es exacta: sin tope de filas, sin filtrar en PHP y sin depender
     * de en qué tipo de hilo acabó el mensaje.
     *
     * `null` en todo lo que no sea un aviso, que es la inmensa mayoría.
     */
    #[ORM\Column(name: 'escalado_de', type: 'string', length: 36, nullable: true)]
    private ?string $escaladoDe = null;

    /** @var Collection<int, WhatsappMetaSendQueue> */
    #[ORM\OneToMany(mappedBy: 'message', targetEntity: WhatsappMetaSendQueue::class, cascade: ['persist', 'remove'])]
    #[Groups(['message:read'])]
    private Collection $whatsappMetaSendQueues;

    /** @var Collection<int, EmailSendQueue> */
    #[ORM\OneToMany(mappedBy: 'message', targetEntity: EmailSendQueue::class, cascade: ['persist', 'remove'])]
    #[Groups(['message:read'])]
    private Collection $emailSendQueues;

    /** @var Collection<int, Beds24SendQueue> */
    #[ORM\OneToMany(mappedBy: 'message', targetEntity: Beds24SendQueue::class, cascade: ['persist', 'remove'])]
    #[Groups(['message:read'])]
    private Collection $beds24SendQueues;

    /** @var Collection<int, MessageAttachment> */
    #[ORM\OneToMany(mappedBy: 'message', targetEntity: MessageAttachment::class, cascade: ['persist', 'remove'])]
    #[Groups(['message:read'])]
    private Collection $attachments;

    #[ORM\Column(length: 10, options: ['default' => 'es'])]
    private string $languageCode = 'es';

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['message:read', 'message:write'])]
    private ?string $contentLocal = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['message:read'])]
    private ?string $contentExternal = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $subjectLocal = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $subjectExternal = null;

    /**
     * Bolsa abierta por canal: `whatsapp`, `beds24`, trazas de despacho…
     *
     * ⚠️ **NO se publica entera, y ésa es la corrección del 10/09/2026.** Llevaba
     * `#[Groups(['message:read'])]` encima, así que cada página de mensajes mandaba al navegador
     * el JSON completo — incluido el `_debug_trace` de auditoría, que había crecido hasta **45,5
     * MB en 3 248 mensajes, el 97 % de la columna**.
     *
     * Medido en producción sobre una conversación de 80 mensajes: la respuesta de
     * `/conversations/{id}/messages` pesaba **2,75 MB** contra una mediana de 58 KB, y el log de
     * nginx enseñaba dónde se iba el tiempo — `req=7.894 up=0.347`: la aplicación contestaba en
     * 347 ms y los otros **7,5 segundos eran transferir el JSON al navegador**. Con las otras
     * llamadas del chat encima, abrir un hilo tardaba diez segundos.
     *
     * Lo publica ahora {@see self::getMetadataPublica()}, que manda **sólo las cuatro claves que
     * el front lee**. El resto se queda en el servidor: no es que sea secreto, es que nadie lo
     * mira y pesa cien veces más que el mensaje.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $metadata = [];

    #[ORM\Column(length: 20, options: ['default' => self::DIRECTION_OUTGOING])]
    #[Groups(['message:read', 'message:write'])]
    private string $direction = self::DIRECTION_OUTGOING;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_PENDING])]
    #[Groups(['message:read', 'message:write'])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 30, options: ['default' => self::SENDER_HOST])]
    #[Groups(['message:read', 'message:write'])]
    private string $senderType = self::SENDER_HOST;

    /** @var array<string, string>|null Identificador del mensaje en cada canal: `['beds24' => '…']`. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $externalIds = [];

    /** @var list<string> Canales elegidos para ESTE envío; no se persiste. */
    #[Groups(['message:write'])]
    private array $transientChannels = [];

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['message:read', 'message:write'])]
    private ?DateTimeImmutable $scheduledAt = null;

    /**
     * CUÁNDO OCURRIÓ, materializado: la única clave por la que se ordena un hilo.
     *
     * ── Por qué una columna y no la expresión de siempre ────────────────────────
     * La fórmula vivía repetida en trece sitios —el getter de la entidad, el `sort` del front y
     * ONCE consultas SQL en resúmenes, menú de entrada y recalentado de hilos—. El 21/09/2026 el
     * getter pasó a ser un máximo y las once copias se quedaron con el `COALESCE` viejo: desde
     * ese momento el chat ordenaba con un criterio y los resúmenes con otro. Una fórmula
     * duplicada no se mantiene, se olvida.
     *
     * Y hay un motivo de rendimiento que pesa igual: `GREATEST(created_at, scheduled_at)` **no
     * usa índice**, y el listado de un hilo es la consulta más caliente del panel. Como columna
     * entra en `(conversation_id, ocurrio_at)` y se ordena por índice.
     *
     * ⚠️ Es DERIVADA: no se pone a mano nunca. La calculan {@see sellarCuandoOcurrio()} al
     * insertar y {@see setScheduledAt()} al reprogramar, que son las dos únicas formas de que
     * cambie. Si algún día se escribe por SQL directo —una migración, un comando—, hay que
     * recalcularla en la misma sentencia.
     *
     * ── Y es NOT NULL desde el 26/09/2026 ───────────────────────────────────────
     * Nació nullable como red: «si una fila entra por un camino que no sea el ORM, mejor un nulo
     * visible que un INSERT que revienta». El precio era que las ONCE consultas seguían llevando
     * `COALESCE(ocurrio_at, created_at)` —la fórmula duplicada que esta columna venía a matar,
     * sólo más corta— y que ninguna de ellas podía usar índice, porque una función sobre la
     * columna lo descarta. Medido en producción: la consulta del acuse pasaba de un
     * `index_merge` de 332 filas con `filesort` a 31 filas de `backward index scan`.
     *
     * El camino que había que temer no existe: no hay un solo `INSERT INTO msg_message` en
     * `src/`. El tipo de la propiedad sigue admitiendo nulo porque una entidad recién construida
     * aún no ha pasado por `PrePersist`; en base de datos no hay ni uno.
     */
    #[ORM\Column(name: 'ocurrio_at', type: 'datetime_immutable')]
    private ?DateTimeImmutable $ocurrioAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->whatsappMetaSendQueues = new ArrayCollection();
        $this->emailSendQueues = new ArrayCollection();
        $this->beds24SendQueues       = new ArrayCollection();
        $this->attachments            = new ArrayCollection();

        // 🔥 INICIALIZACIÓN CRÍTICA PARA EL JSON_MERGE_PATCH
        $this->externalIds = [];
        $this->metadata    = [];
    }

    public function __toString(): string
    {
        return $this->template ? ('Plantilla: ' . $this->template->getName()) : 'Mensaje Libre';
    }

    // =========================================================================
    // LIFECYCLE CALLBACKS
    // =========================================================================

    /**
     * Sella `ocurrio_at` antes de insertar.
     *
     * Va en su PROPIO callback y no dentro de {@see onPrePersist()} porque aquel se va de vacío
     * cuando el mensaje no tiene conversación o está programado a futuro — y son justo los casos
     * en los que la fecha hace más falta. Doctrine admite varios `PrePersist` por entidad.
     *
     * Se asegura `createdAt` antes de calcular: lo pone `TimestampTrait` en otro callback del
     * mismo evento y el orden entre ambos no está garantizado. Es la misma precaución que ya
     * tomaba `onPrePersist()` con su `?: new DateTimeImmutable()`.
     */
    #[ORM\PrePersist]
    public function sellarCuandoOcurrio(): void
    {
        $this->createdAt ??= new DateTimeImmutable();
        $this->ocurrioAt = $this->calcularCuandoOcurrio();
    }

    /** El máximo de las dos fechas. Ver {@see getEffectiveDateTime()} para los tres casos. */
    private function calcularCuandoOcurrio(): ?DateTimeImmutable
    {
        if ($this->scheduledAt === null) {
            return $this->createdAt;
        }

        if ($this->createdAt === null) {
            return $this->scheduledAt;
        }

        return $this->scheduledAt > $this->createdAt ? $this->scheduledAt : $this->createdAt;
    }

    /**
     * Al insertar un mensaje nuevo, actualiza los contadores y fechas de la conversación.
     * Los mensajes programados para el futuro se insertan silenciosamente.
     */
    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if ($this->conversation === null || $this->isScheduledForFuture()) {
            return;
        }

        $msgDate = $this->getEffectiveDateTime() ?: new DateTimeImmutable();

        $currentLast = $this->conversation->getLastMessageAt();
        // Solo actualiza la conversación si el mensaje es realmente MÁS NUEVO
        if ($currentLast === null || $msgDate > $currentLast) {
            $this->conversation->setLastMessageAt($msgDate);
        }

        if ($this->direction === self::DIRECTION_INCOMING) {
            $currentInbound = $this->conversation->getLastInboundAt();
            if ($currentInbound === null || $msgDate > $currentInbound) {
                $this->conversation->setLastInboundAt($msgDate);
            }
            $this->conversation->incrementUnreadCount();

            if ($this->channel?->getId() === 'whatsapp_meta') {
                $this->conversation->setWhatsappSessionValidUntil(
                    DateTimeImmutable::createFromInterface($msgDate)->modify('+24 hours')
                );
            }
        }
    }

    /**
     * Al actualizar un mensaje, solo actualiza lastMessageAt cuando el worker
     * confirma que fue enviado o recibido. No toca unreadCount.
     */
    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        if ($this->conversation !== null &&
            in_array($this->status, [self::STATUS_SENT, self::STATUS_RECEIVED, self::STATUS_READ], true)) {

            $msgDate = $this->getEffectiveDateTime() ?: new DateTimeImmutable();
            $now = new DateTimeImmutable();

            // CORTAFUEGOS: Si el worker actualiza el mensaje, solo subimos el chat
            // si la fecha efectiva del mensaje es <= a HOY.
            if ($msgDate <= $now) {
                $currentLast = $this->conversation->getLastMessageAt();

                // Si el mensaje tiene una fecha más reciente que la conversación, la subimos
                if ($currentLast === null || $msgDate > $currentLast) {
                    $this->conversation->setLastMessageAt($msgDate);
                }
            }
        }
    }

    // =========================================================================
    // GETTERS BÁSICOS
    // =========================================================================

    #[Groups(['message:read'])]
    public function getId(): ?Uuid { return $this->id; }

    #[Groups(['message:read'])]
    public function getCreatedAt(): ?DateTimeInterface { return $this->createdAt ?? null; }

    /**
     * Cuándo OCURRIÓ este mensaje, que es por donde lo ordena y lo fecha el chat.
     *
     * ── Por qué es un máximo y no `scheduledAt ?? createdAt` ────────────────────
     * Porque la hora programada puede estar en el PASADO. El motor crea el mensaje con la hora a
     * la que la regla debía dispararse, y si el cron llega tarde nace a las 09:17 con «prevista
     * 08:00». Con el `??`, esa hora vieja ganaba siempre: el mensaje salía a las 09:17, el
     * huésped lo leía a las 09:17, y en nuestro chat se colocaba a las 08:00 — por encima de
     * todo lo que había pasado en esa hora y media. Aparecía abajo al enviarse y saltaba arriba
     * al recargar. Son 210 mensajes en producción, 28 de ellos con más de un minuto de salto y
     * el peor con 77.
     *
     * El máximo acierta en los tres casos y por eso sustituye al `??`:
     *
     * | caso | scheduledAt | createdAt | ocurrió |
     * |---|---|---|---|
     * | inmediato | — | 09:17 | 09:17 |
     * | programado a futuro y ya enviado | 08/08 | 10/07 | 08/08 ✅ el programado |
     * | programado al pasado (cron tarde) | 08:00 | 09:17 | 09:17 ✅ el creado |
     *
     * Y para los que aún no han salido sigue mandando la programada, porque una fecha futura es
     * siempre mayor que la de creación: la pestaña de «Programados» no cambia.
     *
     * ℹ️ **No hace falta un `sentAt`**, aunque no exista columna que guarde la hora de envío: el
     * mensaje inmediato no espera a ningún cron —se despacha por Messenger en cuanto se crea— y
     * medido en producción la cola se crea en el MISMO segundo y sale dos segundos después. Para
     * el programado, el envío ocurre a su hora prevista, que es justo la que se usa. Las dos
     * ramas ya dan la hora buena.
     *
     * ⚠️ Y ojo al medirlo otra vez: el `updated_at` de la fila de cola NO es la hora de envío.
     * Lo vuelve a tocar cada acuse de entrega y de lectura, así que comparar contra él da medias
     * de media hora que no significan nada.
     */
    #[Groups(['message:read'])]
    public function getEffectiveDateTime(): ?DateTimeInterface
    {
        // La columna manda siempre: en base de datos es NOT NULL. El cálculo queda para el
        // objeto recién construido que todavía no ha pasado por `PrePersist` — nunca para una
        // fila leída.
        return $this->ocurrioAt ?? $this->calcularCuandoOcurrio();
    }

    /**
     * Determina de forma robusta si este mensaje es una programación futura.
     * Evalúa estrictamente que el estado sea PENDING/QUEUED/FAILED y que la fecha objetivo sea mayor a la actual.
     */
    #[Groups(['message:read'])]
    public function isScheduledForFuture(): bool
    {
        if ($this->scheduledAt === null) {
            return false;
        }

        if (!in_array($this->status, [self::STATUS_PENDING, self::STATUS_QUEUED, self::STATUS_FAILED, self::STATUS_SIN_CANAL], true)) {
            return false;
        }

        return $this->scheduledAt > new DateTimeImmutable();
    }

    // =========================================================================
    // RELACIONES
    // =========================================================================

    public function getConversation(): ?MessageConversation { return $this->conversation; }

    /**
     * El hilo de un mensaje GUARDADO, que siempre lo tiene: `conversation_id` es NOT NULL (8 323
     * de 8 323 en producción el 26/09/2026). El getter nulable queda para el mensaje a medio
     * construir. Criterio en `docs/PmsBeds24ReservasSync.md` §12.19.
     */
    public function getConversationOrFail(): MessageConversation
    {
        return $this->conversation
            ?? throw new \LogicException('Mensaje sin conversación (la columna es NOT NULL): #' . ($this->id ?? 'nuevo'));
    }

    public function setConversation(?MessageConversation $conversation): self
    {
        $this->conversation = $conversation;

        if ($conversation !== null && !$conversation->getMessages()->contains($this)) {
            $conversation->addMessage($this);
        }

        return $this;
    }

    public function getChannel(): ?MessageChannel { return $this->channel; }
    public function setChannel(?MessageChannel $channel): self { $this->channel = $channel; return $this; }

    public function getTemplate(): ?MessageTemplate { return $this->template; }
    public function setTemplate(?MessageTemplate $template): self { $this->template = $template; return $this; }

    public function getRule(): ?MessageRule { return $this->rule; }
    public function setRule(?MessageRule $rule): self { $this->rule = $rule; return $this; }

    public function getEscaladoDe(): ?string { return $this->escaladoDe; }
    public function setEscaladoDe(?string $v): self { $this->escaladoDe = $v; return $this; }

    public function getAsuntoType(): ?string { return $this->asuntoType; }
    public function getAsuntoId(): ?string { return $this->asuntoId; }

    /**
     * Para el DESERIALIZADOR, que asigna propiedad a propiedad y no sabe de pares.
     *
     * ⚠️ En código se usa {@see self::setAsunto()}: el par se pone entero o no se pone, y estos
     * dos sueltos permiten dejarlo a medias —un tipo sin id— que es un asunto que no existe.
     * Lo que llega por la API lo contrasta después
     * {@see \App\Message\Service\Conversacion\AsuntoDelMensaje::estampar()} contra los
     * enlaces reales del hilo, y lo descarta si no casa.
     */
    public function setAsuntoType(?string $asuntoType): self { $this->asuntoType = $asuntoType; return $this; }

    /** Ver {@see self::setAsuntoType()}. */
    public function setAsuntoId(?string $asuntoId): self { $this->asuntoId = $asuntoId; return $this; }

    /** Estampa el asunto dueño del mensaje. Ver {@see self::$asuntoType} para el porqué del par. */
    public function setAsunto(?string $asuntoType, ?string $asuntoId): self
    {
        $this->asuntoType = $asuntoType;
        $this->asuntoId   = $asuntoId;

        return $this;
    }

    /** @return list<string> */
    public function getTransientChannels(): array { return $this->transientChannels; }
    /** @param list<string> $channels */
    public function setTransientChannels(array $channels): self { $this->transientChannels = $channels; return $this; }

    public function getScheduledAt(): ?DateTimeImmutable { return $this->scheduledAt; }
    /**
     * ⚠️ Recalcula `ocurrio_at` en el acto, y por eso no vale asignar la propiedad a pelo.
     *
     * Se hace AQUÍ y no en un `PreUpdate` a propósito: en `PreUpdate` el conjunto de cambios ya
     * está calculado, así que tocar una propiedad allí es sutil y depende de que alguien
     * recalcule. Desde el setter, el cambio entra en el mismo `UPDATE` que la reprogramación.
     */
    public function setScheduledAt(?DateTimeImmutable $scheduledAt): self
    {
        $this->scheduledAt = $scheduledAt;
        $this->ocurrioAt = $this->calcularCuandoOcurrio();

        return $this;
    }

    public function getOcurrioAt(): ?DateTimeImmutable { return $this->ocurrioAt; }

    // =========================================================================
    // CAMPOS BÁSICOS
    // =========================================================================

    public function getLanguageCode(): string { return $this->languageCode; }
    public function setLanguageCode(string $languageCode): self { $this->languageCode = $languageCode; return $this; }

    public function getContentLocal(): ?string { return $this->contentLocal; }
    public function setContentLocal(?string $contentLocal): self { $this->contentLocal = $contentLocal; return $this; }

    public function getContentExternal(): ?string { return $this->contentExternal; }
    public function setContentExternal(?string $contentExternal): self { $this->contentExternal = $contentExternal; return $this; }

    public function getSubjectLocal(): ?string { return $this->subjectLocal; }
    public function setSubjectLocal(?string $subjectLocal): self { $this->subjectLocal = $subjectLocal; return $this; }

    public function getSubjectExternal(): ?string { return $this->subjectExternal; }
    public function setSubjectExternal(?string $subjectExternal): self { $this->subjectExternal = $subjectExternal; return $this; }

    /**
     * El texto que se manda a la IA: lo que de verdad escribió quien escribió.
     *
     * Existe porque había CUATRO sitios leyéndolo de tres formas distintas: el pre-router de
     * ráfaga por `contentLocal` a secas, el procesador por `contentExternal` a secas, y otros
     * dos con respaldo. Hoy los mensajes entrantes llenan las dos columnas y por eso funciona;
     * el día que un canal llene sólo una, el que lea la otra vería cadena vacía —y el
     * pre-router, ante un texto vacío, ESPERA SIEMPRE, sin error y sin rastro—.
     *
     * `contentExternal` primero porque es lo que llegó por el canal, tal cual; `contentLocal`
     * es la copia normalizada. Sin el asunto delante, al contrario que
     * {@see self::getFullContentExternal()}: aquí interesa el mensaje, no una cabecera que el
     * huésped no escribió.
     */
    public function getTextoEntrante(): string
    {
        return trim((string) ($this->contentExternal ?? $this->contentLocal ?? ''));
    }

    public function getFullContentLocal(): string
    {
        $content = $this->contentLocal ?? $this->contentExternal ?? '';
        $subject = $this->subjectLocal ?? $this->subjectExternal ?? '';
        if (!empty($subject)) return sprintf("*%s*\n\n%s", trim($subject), trim($content));
        return $content;
    }

    public function getFullContentExternal(): string
    {
        $content = $this->contentExternal ?? $this->contentLocal ?? '';
        $subject = $this->subjectExternal ?? $this->subjectLocal ?? '';
        if (!empty($subject)) return sprintf("*%s*\n\n%s", trim($subject), trim($content));
        return $content;
    }

    // =========================================================================
    // METADATA
    // =========================================================================

    /** @return array<string, mixed> */
    public function getMetadata(): array { return array_merge(['beds24' => [], 'whatsappMeta' => []], $this->metadata); }

    /**
     * Lo único de `metadata` que sale por la API, y por qué es una lista cerrada.
     *
     * Estas cuatro claves son **exactamente** las que lee `util/src/views/ChatView.vue`: los
     * acuses de cada canal (`sent_at`, `delivered_at`, `read_at`, `error_code`, `reactions`) y los
     * avisos de despacho. Todo lo demás que se guarde ahí —payloads crudos de webhook, trazas,
     * lo que venga— se queda en el servidor.
     *
     * ⚠️ **Es lista blanca y no lista negra a propósito.** Excluir `_debug_trace` habría arreglado
     * el caso de hoy y dejado la puerta abierta al siguiente: cualquiera que guarde un payload
     * nuevo en esta bolsa lo publicaría sin enterarse, y el síntoma tarda meses en salir porque
     * empieza pesando kilobytes. Con lista blanca, publicar algo nuevo exige nombrarlo aquí.
     *
     * El nombre expuesto sigue siendo `metadata` (`SerializedName`) para no romper el front ni el
     * esquema: cambia lo que va dentro, no cómo se llama.
     *
     * @return array<string, mixed>
     */
    #[Groups(['message:read'])]
    #[SerializedName('metadata')]
    public function getMetadataPublica(): array
    {
        $publicas = array_intersect_key(
            $this->metadata,
            array_flip(['beds24', 'whatsappMeta', 'dispatch_errors', 'dispatch_warnings'])
        );

        // `beds24` y `whatsappMeta` siempre presentes, como hacía `getMetadata()`: el front
        // encadena `metadata?.beds24?.sent_at` y un `undefined` intermedio le da igual, pero
        // mantener la forma evita que alguien tenga que averiguarlo.
        return array_merge(['beds24' => [], 'whatsappMeta' => []], $publicas);
    }

    /** @param array<string, mixed> $metadata */
    public function setMetadata(array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function addMetadata(string $key, mixed $value): self
    {
        $meta = $this->metadata;
        $meta[$key] = $value;
        $this->metadata = $meta;
        return $this;
    }

    /**
     * Variables para hidratar la plantilla de ESTE mensaje concreto.
     *
     * Normalmente las variables las pone el resolver del contexto
     * ({@see \App\Message\Contract\MessageDataResolverInterface}), que las saca de la entidad
     * dueña de la conversación: la reserva da `guest_name`, `checkin_date`, etc.
     *
     * Eso funciona mientras el dato dependa del CONTEXTO. No sirve cuando depende del HECHO que
     * provoca el mensaje: el aviso de escalado va a la conversación interna de un operador, y lo
     * que hay que contarle —qué huésped, qué pidió, qué chat abrir— no se puede deducir del
     * operador. Un resolver de `staff` devolvería datos del operador, que no es lo que se manda.
     *
     * Estas ganan al resolver cuando la clave coincide, y viajan en la metadata del mensaje para
     * no añadir una columna a una tabla que ya guarda todo lo suyo ahí. Ver docs/Mensajeria.md §5.
     *
     * @return array<string, scalar|null>
     */
    public function getVariablesPlantilla(): array
    {
        // Sólo lo que puede ir en una plantilla: clave de texto y valor escalar. Un array ahí
        // habría salido como la palabra «Array» dentro del mensaje al huésped. Hoy son todas
        // texto (870 en producción, 26/09/2026), así que esto no quita nada.
        $variables = [];

        foreach ($this->bloqueDeMetadata('variables_plantilla') as $clave => $valor) {
            if ($valor === null || is_scalar($valor)) {
                $variables[$clave] = $valor;
            }
        }

        return $variables;
    }

    /**
     * Un bloque anidado de la metadata (`beds24`, `whatsappMeta`…) con sus claves de texto; lo
     * que no sea un objeto es vacío.
     *
     * `metadata` es JSON que hidrata Doctrine sin pasar por los setters, y lo escriben muchos
     * sitios —el envío, los webhooks, el agente—: lo que prometa un tipo aquí lo promete un
     * docblock, no PHP. Los getters leen por este método y por {@see self::textoDeMetadata()}
     * en vez de devolver el valor tal cual; antes, un número donde se esperaba texto era un
     * `TypeError` en el `?string` de retorno.
     *
     * @return array<string, mixed>
     */
    private function bloqueDeMetadata(string $bloque): array
    {
        $valor = $this->metadata[$bloque] ?? null;

        if (!is_array($valor)) {
            return [];
        }

        // Un objeto JSON sólo tiene claves de texto, salvo las numéricas («"0"»), que
        // `json_decode()` convierte en enteros. Ninguno de estos bloques las usa.
        return array_filter($valor, is_string(...), ARRAY_FILTER_USE_KEY);
    }

    /** Un texto dentro de un bloque de la metadata; lo que no sea texto es «no está». */
    private function textoDeMetadata(string $bloque, string $clave): ?string
    {
        $valor = $this->bloqueDeMetadata($bloque)[$clave] ?? null;

        return is_string($valor) ? $valor : null;
    }

    /** @param array<string, scalar|null> $variables */
    public function setVariablesPlantilla(array $variables): self
    {
        return $this->addMetadata('variables_plantilla', $variables);
    }

    /** @return array<string, mixed> */
    public function getBeds24Metadata(): array { return $this->bloqueDeMetadata('beds24'); }

    /** @param array<string, mixed> $data */
    public function setBeds24Metadata(array $data): self
    {
        $meta = $this->metadata;
        $meta['beds24'] = $data;
        $this->metadata = $meta;
        return $this;
    }

    public function addBeds24Metadata(string $key, mixed $value): self
    {
        $meta = $this->metadata;
        if (!isset($meta['beds24']) || !is_array($meta['beds24'])) {
            $meta['beds24'] = [];
        }
        $meta['beds24'][$key] = $value;

        $this->metadata = $meta;
        return $this;
    }

    public function getBeds24SentAt(): ?string { return $this->textoDeMetadata('beds24', 'sent_at'); }
    public function setBeds24SentAt(string $dateTimeIso8601): self { return $this->addBeds24Metadata('sent_at', $dateTimeIso8601); }
    public function getBeds24ReceivedAt(): ?string { return $this->textoDeMetadata('beds24', 'received_at'); }
    public function setBeds24ReceivedAt(string $dateTimeIso8601): self { return $this->addBeds24Metadata('received_at', $dateTimeIso8601); }
    public function getBeds24ReadAt(): ?string { return $this->textoDeMetadata('beds24', 'read_at'); }
    public function setBeds24ReadAt(string $dateTimeIso8601): self { return $this->addBeds24Metadata('read_at', $dateTimeIso8601); }

    /** @return array<string, mixed> */
    public function getWhatsappMetaMetadata(): array { return $this->bloqueDeMetadata('whatsappMeta'); }

    /** @param array<string, mixed> $data */
    public function setWhatsappMetaMetadata(array $data): self
    {
        $meta = $this->metadata;
        $meta['whatsappMeta'] = $data;
        $this->metadata = $meta;
        return $this;
    }

    public function addWhatsappMetaMetadata(string $key, mixed $value): self
    {
        $meta = $this->metadata;
        if (!isset($meta['whatsappMeta']) || !is_array($meta['whatsappMeta'])) {
            $meta['whatsappMeta'] = [];
        }
        $meta['whatsappMeta'][$key] = $value;

        $this->metadata = $meta;
        return $this;
    }

    public function getWhatsappMetaSentAt(): ?string { return $this->textoDeMetadata('whatsappMeta', 'sent_at'); }
    public function setWhatsappMetaSentAt(string $dateTimeIso8601): self { return $this->addWhatsappMetaMetadata('sent_at', $dateTimeIso8601); }
    public function getWhatsappMetaDeliveredAt(): ?string { return $this->textoDeMetadata('whatsappMeta', 'delivered_at'); }
    public function setWhatsappMetaDeliveredAt(string $dateTimeIso8601): self { return $this->addWhatsappMetaMetadata('delivered_at', $dateTimeIso8601); }
    public function getWhatsappMetaReadAt(): ?string { return $this->textoDeMetadata('whatsappMeta', 'read_at'); }
    public function setWhatsappMetaReadAt(string $dateTimeIso8601): self { return $this->addWhatsappMetaMetadata('read_at', $dateTimeIso8601); }
    public function getWhatsappMetaErrorCode(): ?string { return $this->textoDeMetadata('whatsappMeta', 'error_code'); }
    public function setWhatsappMetaErrorCode(string $code): self { return $this->addWhatsappMetaMetadata('error_code', $code); }
    public function getWhatsappMetaErrorReason(): ?string { return $this->textoDeMetadata('whatsappMeta', 'error_reason'); }
    public function setWhatsappMetaErrorReason(string $reason): self { return $this->addWhatsappMetaMetadata('error_reason', $reason); }

    // =========================================================================
    // ESTADO Y DIRECCIÓN
    // =========================================================================

    public function getDirection(): string { return $this->direction; }

    /**
     * Por qué este mensaje ya no debe salir por ninguna de sus colas, o `null` si puede.
     *
     * Lo pregunta el motor de intercambio justo antes de enviar (`FiltroDeVetos`), a través de las
     * tres colas de envío. Es la última puerta: un mensaje cancelado con una cola viva no sale,
     * venga de donde venga el desajuste.
     *
     * ⚠️ **Sólo `cancelled`, y sólo salientes.** Las otras dos cosas que parecen terminales no lo
     * son para una cola concreta:
     *
     * - **`sent`** lo pone la PRIMERA cola que sale. Con Beds24 y WhatsApp a la vez, vetar por
     *   `sent` cortaría el WhatsApp de un mensaje que ya salió por Booking.
     * - **`sin_canal`** y **`failed`** siguen vivos: otro canal puede aparecer o reintentarse.
     * - **Los entrantes** viajan por estas mismas colas como acuses de lectura, ya en `read`:
     *   vetarlos dejaría de marcar como leído en Beds24 y WhatsApp.
     */
    public function motivoParaNoEnviar(): ?string
    {
        if ($this->direction !== self::DIRECTION_OUTGOING || $this->status !== self::STATUS_CANCELLED) {
            return null;
        }

        return 'El mensaje se canceló después de encolarse: no se envía.';
    }
    public function setDirection(string $direction): self {
        if (!in_array($direction, [self::DIRECTION_INCOMING, self::DIRECTION_OUTGOING])) {
            throw new InvalidArgumentException("Dirección inválida");
        }
        $this->direction = $direction;
        return $this;
    }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getSenderType(): string { return $this->senderType; }
    public function setSenderType(string $senderType): self { $this->senderType = $senderType; return $this; }

    // =========================================================================
    // IDS EXTERNOS
    // =========================================================================

    /** @return array<string, string> */
    public function getExternalIds(): array { return $this->externalIds ?? []; }

    /** @param array<string, string>|null $externalIds */
    public function setExternalIds(?array $externalIds): self
    {
        $this->externalIds = $externalIds;
        return $this;
    }

    public function getBeds24ExternalId(): ?string { return $this->externalIds['beds24'] ?? null; }

    public function setBeds24ExternalId(?string $id): self
    {
        $ext = $this->externalIds ?? [];
        // Un id nulo QUITA la clave: el mapa es de texto, y un `null` dentro se guardaba como
        // `"beds24": null` en el JSON, contradiciendo su propio tipo.
        if ($id === null) {
            unset($ext['beds24']);
        } else {
            $ext['beds24'] = $id;
        }
        $this->externalIds = $ext;
        return $this;
    }

    public function getWhatsappMetaExternalId(): ?string { return $this->externalIds['whatsapp_meta'] ?? null; }

    public function setWhatsappMetaExternalId(?string $id): self
    {
        $ext = $this->externalIds ?? [];
        // Un id nulo QUITA la clave: el mapa es de texto, y un `null` dentro se guardaba como
        // `"whatsapp_meta": null` en el JSON, contradiciendo su propio tipo.
        if ($id === null) {
            unset($ext['whatsapp_meta']);
        } else {
            $ext['whatsapp_meta'] = $id;
        }
        $this->externalIds = $ext;
        return $this;
    }

    // =========================================================================
    // COLECCIONES
    // =========================================================================

    /** @return Collection<int, EmailSendQueue> */
    public function getEmailSendQueues(): Collection { return $this->emailSendQueues; }

    public function addEmailSendQueue(EmailSendQueue $queue): self
    {
        if (!$this->emailSendQueues->contains($queue)) {
            $this->emailSendQueues->add($queue);
            $queue->setMessage($this);
        }

        return $this;
    }

    /** @return Collection<int, WhatsappMetaSendQueue> */
    public function getWhatsappMetaSendQueues(): Collection { return $this->whatsappMetaSendQueues; }

    public function addWhatsappMetaSendQueue(WhatsappMetaSendQueue $queue): self
    {
        if (!$this->whatsappMetaSendQueues->contains($queue)) {
            $this->whatsappMetaSendQueues->add($queue);
            if ($queue->getMessage() !== $this) $queue->setMessage($this);
        }
        return $this;
    }

    /** @return Collection<int, Beds24SendQueue> */
    public function getBeds24SendQueues(): Collection { return $this->beds24SendQueues; }
    public function addBeds24SendQueue(Beds24SendQueue $queue): self
    {
        if (!$this->beds24SendQueues->contains($queue)) {
            $this->beds24SendQueues->add($queue);
            if ($queue->getMessage() !== $this) $queue->setMessage($this);
        }
        return $this;
    }

    /** @return Collection<int, MessageAttachment> */
    public function getAttachments(): Collection { return $this->attachments; }

    public function addAttachment(MessageAttachment $attachment): self
    {
        if (!$this->attachments->contains($attachment)) {
            $this->attachments->add($attachment);
            if ($attachment->getMessage() !== $this) $attachment->setMessage($this);
        }
        return $this;
    }

    // =========================================================================
    // AUTO-RESPONDER / INTENT ROUTER HELPERS
    // =========================================================================

    /**
     * Recupera la intención inyectada por los Webhooks para el motor de Inteligencia Artificial
     * o el enrutador determinista.
     *
     * @return array<string, mixed>|null
     */
    public function getInboundIntent(): ?array
    {
        // `null` y no `[]` cuando no hay intención: los llamadores distinguen «no hay» de «está
        // vacía» (`ProcessInboundIntentDispatchHandler` sale con el primero).
        return is_array($this->metadata['inbound_intent'] ?? null) ? $this->bloqueDeMetadata('inbound_intent') : null;
    }

    /**
     * Define la intención de entrada para ser evaluada asíncronamente por el Autorresponder.
     *
     * @param array<string, mixed> $intentData
     */
    public function setInboundIntent(array $intentData): self
    {
        return $this->addMetadata('inbound_intent', $intentData);
    }

    // =========================================================================
    // AUDITORÍA INTERNA
    // =========================================================================

    /**
     * ⚠️ **AQUÍ VIVÍA `appendDebugTrace()`, y se retiró el 10/09/2026.**
     *
     * Era un «HACK DE AUDITORÍA» —lo decía su propio docblock— puesto para cazar un bug real:
     * dos procesos leían `metadata`, cada uno añadía su clave y el segundo pisaba la del primero.
     * Apilaba en el JSON una entrada con `debug_backtrace()` **en cada escritura**, con el valor
     * entero dentro.
     *
     * Se quitó porque el bug está cerrado y se comprobó, no se supuso. Repasando todas las
     * escrituras de julio a septiembre contra el metadata final: **3 558 comprobadas, 0 cuyo
     * rastro no sobreviva**. Las 899 escrituras concurrentes que quedan escriben el MISMO valor
     * y las 124 «distintas» son progresión legítima (`resolved:false` → `resolved:true`).
     *
     * Y el precio de dejarlo puesto estaba medido: **45,5 MB en 3 248 mensajes, el 97 % de la
     * columna**, y 3 479 entradas en el peor mensaje.
     *
     * ⚠️ **Al investigarlo se le atribuyó además un «Out of sort memory» de MySQL, y era falso.**
     * Un `SELECT id, metadata … ORDER BY … LIMIT 30` sobre esta tabla muere con
     * `sort_buffer_size` en 256 KB — pero **falla igual con el metadata ya purgado y sobre
     * mensajes que nunca tuvieron traza**, y no falla si en vez de la columna JSON se pide
     * `content_local`, que es TEXT. Es el ancho DECLARADO de la columna lo que MySQL reserva en
     * el buffer, no lo que hay dentro. Un problema real y distinto, anterior a esto, que no se
     * arregla borrando datos. Producción lo esquiva porque el paginador de Doctrine pide los ids
     * primero y las entidades después.
     *
     * **Si el bug vuelve, no se resucita esto.** Un instrumento de diagnóstico que crece sin
     * límite dentro del dato que vigila acaba costando más que el fallo: se acota por número de
     * entradas, se guarda fuera de la fila, o se pone detrás de una variable de entorno.
     */

    /**
     * Agrupa todas las colas físicas asociadas a este mensaje en una única colección agnóstica.
     * * ¿Por qué existe?: Evita fugas de abstracción. Permite que servicios de dominio
     * (como MessageRuleEngine o el Dispatcher) puedan iterar y evaluar el estado de todas
     * las colas sin necesidad de acoplarse a las colecciones físicas individuales de cada canal
     * (Beds24, WhatsApp, etc.), respetando el Open/Closed Principle.
     * * Dependencias y Efectos: Este es el único punto de convergencia entre las colecciones
     * físicas de Doctrine y los contratos de dominio. Si en el futuro se añade un nuevo
     * canal de salida (ej. SMS Twilio), su colección Doctrine DEBE sumarse obligatoriamente
     * dentro de este método para que el motor de reglas pueda auditarlo.
     *
     * @return MessageQueueItemInterface[] Arreglo tipado donde cada elemento cumple el contrato de cola.
     */
    public function getAllQueues(): array
    {
        $queues = [];

        // Sin guarda de null: las dos colecciones se inicializan en el constructor y Doctrine
        // las repone al hidratar. Nunca son null, y comprobarlo hacía creer lo contrario.
        foreach ($this->beds24SendQueues as $q) {
            $queues[] = $q;
        }

        foreach ($this->whatsappMetaSendQueues as $q) {
            $queues[] = $q;
        }

        foreach ($this->emailSendQueues as $q) {
            $queues[] = $q;
        }

        return $queues;
    }

    /**
     * Añade una cola a la colección física que le corresponde, sin que quien la crea
     * tenga que saber de qué canal es.
     *
     * ES EL PUNTO ÚNICO que conoce las clases concretas de cola. Antes esta decisión estaba
     * repartida por MessageEnqueuerEntityListener con `str_contains(get_class($queue),'Beds24')`
     * y `method_exists($message,'addBeds24SendQueue')`, así que **cada channel manager nuevo
     * obligaba a tocar el listener en varios sitios** — y olvidar uno no rompía nada: sólo
     * dejaba de cancelar, y el mensaje salía por un canal que ya no tocaba.
     *
     * 🔥 Al añadir un canal (Beds24, WhatsApp, el CM que venga) se tocan DOS métodos y ninguno
     * más: éste y `getAllQueues()`. Ver docs/Mensajeria.md §5.
     */
    public function addQueue(MessageQueueItemInterface $queue): self
    {
        if ($queue instanceof Beds24SendQueue) {
            $this->addBeds24SendQueue($queue);
        } elseif ($queue instanceof WhatsappMetaSendQueue) {
            $this->addWhatsappMetaSendQueue($queue);
        } elseif ($queue instanceof EmailSendQueue) {
            $this->addEmailSendQueue($queue);
        }

        return $this;
    }
}