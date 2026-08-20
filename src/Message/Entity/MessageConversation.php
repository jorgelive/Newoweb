<?php

declare(strict_types=1);

namespace App\Message\Entity;

use App\Contract\VinculoComercial;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\Entity\Maestro\MaestroIdioma;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Contract\ConversationMilestoneInterface;
use App\Contract\MapaDeHitos;
use App\Contract\MomentoDeHito;
use App\Message\Controller\Api\MarkConversationReadController;
use App\Message\Controller\Api\UnreadSummaryController;
use App\Security\Roles;
use DateTime;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'msg_conversation')]
#[ApiResource(
    shortName: 'Conversation',
    operations: [
        // Resumen global de no leídos: GET /message/conversations/unread-summary
        //
        // Va ANTES que el Get de item a propósito: las rutas se registran en este
        // orden y `/conversations/{id}` capturaría «unread-summary» como si fuera
        // un identificador.
        //
        // `read: false` porque no hay colección que proveer —el controlador hace
        // su propia consulta agregada— y `output: false` porque ya devuelve una
        // JsonResponse montada. Ver UnreadSummaryController.
        new GetCollection(
            uriTemplate: '/conversations/unread-summary',
            controller: UnreadSummaryController::class,
            openapi: new Operation(
                summary: 'Resumen de mensajes sin leer',
                description: 'Total de mensajes sin leer, desglose por estado y las conversaciones pendientes. Fuente única del badge y de los contadores del portal.'
            ),
            paginationEnabled: false,
            // El escudo se declara AQUÍ y no se deja al `security` global del
            // ApiResource: con `read: false` no se aplicó, y el endpoint estuvo
            // sirviendo nombres de huéspedes y contadores SIN sesión mientras la
            // colección normal sí redirigía a login. Si añades otra operación con
            // `read: false`, ponle su propio `security` y compruébalo con curl sin
            // cookies antes de darla por buena.
            security: "is_granted('" . Roles::MENSAJES_SHOW . "')",
            securityMessage: 'Acceso denegado a las conversaciones.',
            read: false,
            deserialize: false,
            output: false
        ),

        // API Platform infiere automáticamente: GET /message/conversations
        new GetCollection(),

        // API Platform infiere automáticamente: GET /message/conversations/{id}
        // Hereda el escudo global de lectura
        new Get(),

        // Para operaciones custom, solo escribes la parte final de la ruta.
        // API Platform lo concatena: POST /message/conversations/{id}/read
        new Post(
            uriTemplate: '/conversations/{id}/read',
            controller: MarkConversationReadController::class,
            openapi: new Operation(
                summary: 'Marca el chat como leído',
                description: 'Marca todos los mensajes INCOMING de la conversación como leídos y notifica a las OTAs.'
            ),
            // 🔥 Verificamos ÚNICAMENTE el rol explícito de escritura
            security: "is_granted('" . Roles::MENSAJES_WRITE . "')",
            securityMessage: 'No tienes permisos para alterar el estado de las conversaciones.',
            deserialize: false
        ),

        // Edición de la cabecera de la conversación (nombre/teléfono del huésped, idioma, WhatsApp).
        new Patch(
            denormalizationContext: ['groups' => ['conversation:write']],
            security: "is_granted('" . Roles::MENSAJES_WRITE . "')",
            securityMessage: 'No tienes permiso para editar la conversación.'
        ),

        new Delete(
            security: "is_granted('" . Roles::MENSAJES_DELETE . "')",
            securityMessage: 'No tienes permiso para eliminar conversaciones.'
        )
    ], // 🔥 El módulo define la raíz para todas las operaciones
    routePrefix: '/message',
    normalizationContext: ['groups' => ['conversation:read']],
    order: ['lastMessageAt' => 'DESC'],

    // 🔥 Escudo global: Solo usuarios con permiso de ver conversaciones entran aquí
    security: "is_granted('" . Roles::MENSAJES_SHOW . "')",
    securityMessage: 'Acceso denegado a las conversaciones.'
)]
#[ApiFilter(OrderFilter::class, properties: ['lastMessageAt' => 'DESC', 'createdAt' => 'DESC'])]
#[ApiFilter(SearchFilter::class, properties: ['contextType' => 'exact', 'contextId' => 'exact'])]
#[ORM\HasLifecycleCallbacks]
class MessageConversation
{
    use IdTrait;
    use TimestampTrait;

    public const string STATUS_OPEN     = 'open';
    public const string STATUS_CLOSED   = 'closed';
    public const string STATUS_ARCHIVED = 'archived';

    #[ORM\Column(type: 'string', length: 20, options: ['default' => self::STATUS_OPEN])]
    #[Groups(['conversation:read', 'conversation:write'])]
    private string $status = self::STATUS_OPEN;

    #[ORM\Column(type: 'string', length: 50)]
    #[Groups(['conversation:read'])]
    private string $contextType;

    #[ORM\Column(type: 'string', length: 100)]
    #[Groups(['conversation:read'])]
    private string $contextId;

    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    #[Groups(['conversation:read', 'conversation:write'])]
    private ?string $guestName = null;

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    #[Groups(['conversation:read', 'conversation:write'])]
    private ?string $guestPhone = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['conversation:read', 'conversation:write'])]
    private bool $whatsappDisabled = false;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    #[Groups(['conversation:read', 'conversation:write'])]
    private ?string $whatsappDisabledReason = null;

    #[ORM\ManyToOne(targetEntity: MaestroIdioma::class)]
    #[ORM\JoinColumn(name: 'idioma_id', referencedColumnName: 'id', nullable: false)]
    #[Groups(['conversation:read', 'conversation:write'])]
    private MaestroIdioma $idioma;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['conversation:read', 'conversation:write'])]
    private bool $idiomaFijado = false;

    /**
     * Resumen en una línea de lo que el huésped ha escrito DESDE LA ÚLTIMA RESPUESTA
     * del equipo. Lo genera la IA en segundo plano; ver ResumenConversacionService.
     *
     * Se guarda en la conversación —en vez de calcularse al vuelo— porque lo leen tres
     * sitios distintos: el cuerpo de la notificación push, el panel de chats sin leer
     * del portal y la bandeja del chat. Una llamada al modelo, tres consumidores.
     *
     * `null` significa «todavía no hay resumen» (recién llegado, IA apagada o el motor
     * falló): quien lo pinta debe caer al texto del último mensaje, nunca dejar el
     * hueco vacío.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['conversation:read'])]
    private ?string $resumenIa = null;

    /**
     * Fecha del mensaje más reciente que YA está reflejado en `resumenIa`.
     *
     * Es lo que hace idempotente al handler: una ráfaga de cuatro mensajes encola
     * cuatro trabajos, el primero resume y los otros tres ven que no hay nada nuevo y
     * se van sin llamar al modelo. Sin esto se pagarían cuatro llamadas por lo mismo.
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $resumenIaHasta = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    #[Groups(['conversation:read'])]
    private ?DateTimeInterface $lastMessageAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    #[Groups(['conversation:read'])]
    private ?DateTimeInterface $lastInboundAt = null;

    /**
     * Puntero exacto para la ventana de servicio de 24 horas de WhatsApp (Meta).
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    #[Groups(['conversation:read'])]
    private ?DateTimeInterface $whatsappSessionValidUntil = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Groups(['conversation:read'])]
    private int $unreadCount = 0;

    /** @var array<string, mixed>|null Espejo del activo: hitos, ítems, financieros. Ver `docs/Mensajeria.md`. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $contextData = [];

    /** @var Collection<int, Message> */
    #[ORM\OneToMany(mappedBy: 'conversation', targetEntity: Message::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $messages;

    public function __construct(string $contextType, string $contextId)
    {
        $this->contextType = $contextType;
        $this->contextId   = $contextId;
        $this->messages    = new ArrayCollection();
        $this->identidades = new ArrayCollection();
        $this->contextData = [];
        $this->id          = Uuid::v7();
    }

    // =========================================================================
    // GETTERS BÁSICOS
    // =========================================================================

    #[Groups(['conversation:read'])]
    public function getId(): ?Uuid { return $this->id; }

    #[Groups(['conversation:read'])]
    public function getCreatedAt(): ?DateTimeInterface { return $this->createdAt ?? null; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self
    {
        $validStatuses = [self::STATUS_OPEN, self::STATUS_CLOSED, self::STATUS_ARCHIVED];
        if (!in_array($status, $validStatuses, true)) {
            throw new InvalidArgumentException(sprintf('El estado "%s" no es válido.', $status));
        }
        $this->status = $status;
        return $this;
    }

    public function getContextType(): string { return $this->contextType; }
    public function getContextId(): string { return $this->contextId; }

    /**
     * Un hilo que nació SIN saber de qué iba pasa a tener asunto.
     *
     * `manual` no es un contexto: es el marcador de «entró un número que no reconocíamos»
     * (`WhatsappMetaReceivePersister::resolveConversation()`, rama D). Cuando esa persona
     * aparece luego en una reserva, la resolución por identidad devuelve ESTE hilo —que es
     * justo lo que se quiere, el historial es de la persona— pero la cabecera se quedaba
     * diciendo `manual` para siempre.
     *
     * Y eso no es cosmético: el agente todavía decide por `getContextType()`
     * (`EnviarPlantillaSkill`, `EscalarAlEquipoSkill`, `AiConversationProcessor`), así que un
     * hilo `manual` con reserva enlazada le contestaría al huésped que no tiene ninguna
     * reserva. Antes no pasaba porque nacían dos hilos separados; desde que resuelven al
     * mismo, hay que promover.
     *
     * Sólo se sube DESDE `manual`. Una cabecera que ya tiene asunto real no se pisa: ahí la
     * respuesta es un enlace más, no reescribir de qué va el hilo.
     */
    public function promoverDesdeManual(string $contextType, string $contextId): bool
    {
        if ($this->contextType !== 'manual' || $contextType === 'manual') {
            return false;
        }

        $this->contextType = $contextType;
        $this->contextId   = $contextId;

        return true;
    }

    public function getGuestName(): ?string { return $this->guestName; }
    public function setGuestName(?string $guestName): self { $this->guestName = $guestName; return $this; }

    public function getGuestPhone(): ?string { return $this->guestPhone; }

    /**
     * Cambiar el teléfono LEVANTA el bloqueo de WhatsApp.
     *
     * `whatsappDisabled` lo pone `WhatsappMetaReceivePersister` cuando Meta rechaza un envío
     * —«número inválido»— y hasta ahora **nadie lo quitaba nunca**: no había un solo
     * `setWhatsappDisabled(false)` en todo el proyecto. Corregir el número no servía de nada,
     * porque `WhatsappMetaSendEnqueuer` sigue mirando el flag y aborta antes de intentarlo.
     * La conversación quedaba muerta para WhatsApp de forma permanente.
     *
     * Lo que despistaba es que la re-evaluación de reglas SÍ funciona: `guestPhone` está en
     * los campos críticos de `MessageRuleEngineListener`, y editar el teléfono en la reserva
     * dispara `PmsReservaRecalculoListener` → `upsertFromContext()` → este setter. Las reglas
     * se recalculaban bien; lo que no se levantaba era el veto.
     *
     * El bloqueo era sobre el número ANTIGUO. Con uno nuevo hay que volver a intentarlo: si
     * también es malo, Meta lo rechazará otra vez y el flag se pondrá solo.
     *
     * ⚠️ Sólo cuando el valor CAMBIA de verdad. `upsertFromContext()` corre en cada mensaje
     * entrante de Beds24 y reescribe el teléfono con el mismo valor: sin esta comparación,
     * cualquier mensaje entrante desbloquearía un número legítimamente vetado.
     */
    public function setGuestPhone(?string $guestPhone): self
    {
        if ($this->guestPhone !== $guestPhone && $this->whatsappDisabled) {
            $this->whatsappDisabled = false;
            $this->whatsappDisabledReason = null;
        }

        $this->guestPhone = $guestPhone;

        return $this;
    }

    // Getters y Setters
    public function isWhatsappDisabled(): bool { return $this->whatsappDisabled; }
    public function setWhatsappDisabled(bool $disabled): self { $this->whatsappDisabled = $disabled; return $this; }

    public function getWhatsappDisabledReason(): ?string { return $this->whatsappDisabledReason; }
    public function setWhatsappDisabledReason(?string $reason): self { $this->whatsappDisabledReason = $reason; return $this; }

    public function getIdioma(): MaestroIdioma { return $this->idioma; }
    public function setIdioma(MaestroIdioma $idioma): self { $this->idioma = $idioma; return $this; }

    public function isIdiomaFijado(): bool { return $this->idiomaFijado; }
    public function setIdiomaFijado(bool $idiomaFijado): self { $this->idiomaFijado = $idiomaFijado; return $this; }

    // =========================================================================
    // FECHAS Y CONTADORES
    // =========================================================================

    public function getLastMessageAt(): ?DateTimeInterface { return $this->lastMessageAt; }
    public function setLastMessageAt(?DateTimeInterface $lastMessageAt): self { $this->lastMessageAt = $lastMessageAt; return $this; }

    public function getLastInboundAt(): ?DateTimeInterface { return $this->lastInboundAt; }
    public function setLastInboundAt(?DateTimeInterface $lastInboundAt): self { $this->lastInboundAt = $lastInboundAt; return $this; }

    public function getWhatsappSessionValidUntil(): ?DateTimeInterface { return $this->whatsappSessionValidUntil; }
    public function setWhatsappSessionValidUntil(?DateTimeInterface $whatsappSessionValidUntil): self {
        $this->whatsappSessionValidUntil = $whatsappSessionValidUntil;
        return $this;
    }

    /**
     * Comprueba si la ventana de servicio de 24 horas de WhatsApp está abierta.
     * Si devuelve true, se pueden enviar mensajes libres.
     * Si devuelve false, SOLO se pueden enviar plantillas pre-aprobadas.
     */
    #[Groups(['conversation:read'])]
    public function isWhatsappSessionActive(): bool
    {
        if ($this->whatsappSessionValidUntil === null) {
            return false;
        }
        return $this->whatsappSessionValidUntil > new DateTime();
    }

    public function getResumenIa(): ?string { return $this->resumenIa; }
    public function setResumenIa(?string $resumenIa): self { $this->resumenIa = $resumenIa; return $this; }

    public function getResumenIaHasta(): ?DateTimeInterface { return $this->resumenIaHasta; }
    public function setResumenIaHasta(?DateTimeInterface $resumenIaHasta): self { $this->resumenIaHasta = $resumenIaHasta; return $this; }

    public function getUnreadCount(): int { return $this->unreadCount; }
    public function setUnreadCount(int $unreadCount): self { $this->unreadCount = $unreadCount; return $this; }
    public function incrementUnreadCount(): self { $this->unreadCount++; return $this; }
    public function resetUnreadCount(): self { $this->unreadCount = 0; return $this; }

    /** @return array<string, mixed> */
    public function getContextData(): ?array { return $this->contextData; }
    /** @param array<string, mixed>|null $contextData */
    public function setContextData(?array $contextData): self { $this->contextData = $contextData; return $this; }

    // =========================================================================
    // COLECCIÓN DE MENSAJES
    // =========================================================================

    /**
     * @return Collection<int, Message>
     */
    #[Groups(['conversation:read'])]
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    /**
     * Añade un mensaje a la colección y establece la relación bidireccional.
     * Los contadores y fechas se actualizan via PrePersist en Message.
     */
    public function addMessage(Message $message): self
    {
        if (!$this->messages->contains($message)) {
            $this->messages->add($message);
            $message->setConversation($this);
        }
        return $this;
    }

    /**
     * Elimina un mensaje y rompe la relación bidireccional de forma segura.
     */
    public function removeMessage(Message $message): self
    {
        if ($this->messages->removeElement($message)) {
            if ($message->getConversation() === $this) {
                $message->setConversation(null);
            }
        }
        return $this;
    }

    // =========================================================================
    // CONTEXT DATA
    // =========================================================================

    private function initContextData(): void
    {
        if ($this->contextData === null) {
            $this->contextData = [];
        }
    }

    #[Groups(['conversation:read'])]
    public function getContextOrigin(): ?string { return $this->contextData['origin'] ?? null; }
    public function setContextOrigin(?string $origin): self {
        $this->initContextData();
        $this->contextData['origin'] = $origin;
        return $this;
    }

    /**
     * Agencia mayorista dueña del contexto. Alimenta el filtro `allowedAgencies`
     * de MessageRule::matchesSegmentation().
     */
    #[Groups(['conversation:read'])]
    public function getContextAgency(): ?string { return $this->contextData['agency'] ?? null; }
    public function setContextAgency(?string $agency): self {
        $this->initContextData();
        $this->contextData['agency'] = $agency;
        return $this;
    }

    #[Groups(['conversation:read'])]
    public function getContextStatusTag(): ?string { return $this->contextData['status_tag'] ?? null; }

    /**
     * El vínculo que declaró el contexto, o `null` en conversaciones anteriores al campo.
     *
     * Quien lo consuma debe tratar el `null` como «no lo sé» y no como «ninguno»: hasta que el
     * upsert vuelva a correr sobre una conversación vieja, aquí no hay nada. El agente resuelve
     * ese hueco cayendo a `VinculoComercial::deStatusTag()`, que es de donde salía antes.
     */
    public function getContextVinculo(): ?VinculoComercial
    {
        $valor = $this->contextData['vinculo'] ?? null;

        return is_string($valor) ? VinculoComercial::tryFrom($valor) : null;
    }

    public function setContextVinculo(?VinculoComercial $vinculo): self
    {
        $this->initContextData();
        $this->contextData['vinculo'] = $vinculo?->value;

        return $this;
    }
    public function setContextStatusTag(?string $statusTag): self {
        $this->initContextData();
        $this->contextData['status_tag'] = $statusTag;
        return $this;
    }

    /**
     * La forma persistida y la que sale por la API. No cambia de tipo: `conversation:read` la
     * serializa tal cual y el front la consume así.
     *
     * @return array<string, string>
     */
    #[Groups(['conversation:read'])]
    public function getContextMilestones(): array { return $this->contextData['milestones'] ?? []; }

    /** Los mismos hitos, ya tipados, para operar con ellos. */
    public function getMapaDeHitos(): MapaDeHitos
    {
        return MapaDeHitos::desdeCrudo($this->contextData['milestones'] ?? []);
    }

    public function setContextMilestones(MapaDeHitos $hitos): self {
        $this->initContextData();
        $this->contextData['milestones'] = [];
        foreach ($hitos->todos() as $clave => $momento) {
            $this->addContextMilestone($clave, $momento);
        }
        return $this;
    }

    /**
     * ⚠️ **La lista de aquí es MÁS CORTA que el vocabulario completo, y a propósito.**
     *
     * Este mapa es el de la CONVERSACIÓN, que es anterior a los enlaces por asunto y sólo sabe
     * de los cinco hitos originales. Los demás —salida temporal, reingreso, cambio de casita,
     * servicio, cancelación parcial— son del asunto y viven en `PmsConversacionEnlace`; que aquí
     * los rechace es la señal de que alguien está escribiendo en el sitio equivocado.
     */
    public function addContextMilestone(string $key, ?MomentoDeHito $momento): self
    {
        $validMilestones = [
            ConversationMilestoneInterface::CREATED,
            ConversationMilestoneInterface::START,
            ConversationMilestoneInterface::END,
            ConversationMilestoneInterface::EXPECTED_ARRIVAL,
            ConversationMilestoneInterface::CANCELLED,
        ];

        if (!in_array($key, $validMilestones, true)) {
            throw new InvalidArgumentException(sprintf(
                'El milestone "%s" no es válido. Solo se permiten las constantes definidas en %s.',
                $key,
                ConversationMilestoneInterface::class
            ));
        }

        $this->initContextData();
        if (!isset($this->contextData['milestones'])) {
            $this->contextData['milestones'] = [];
        }

        if ($momento === null) {
            return $this;
        }

        $this->contextData['milestones'][$key] = $momento->comoTexto();

        return $this;
    }

    #[Groups(['conversation:read'])]
    /** @return list<string> */
    public function getContextItems(): array { return $this->contextData['items'] ?? []; }
    /** @param list<string> $items */
    public function setContextItems(array $items): self {
        $this->initContextData();
        $this->contextData['items'] = array_values($items);
        return $this;
    }

    #[Groups(['conversation:read'])]
    public function getContextFinancialTotal(): ?float {
        return isset($this->contextData['financials']['total'])
            ? (float) $this->contextData['financials']['total']
            : null;
    }

    #[Groups(['conversation:read'])]
    public function getContextFinancialIsCleared(): bool {
        return (bool) ($this->contextData['financials']['is_cleared'] ?? false);
    }

    public function setContextFinancials(?float $total, bool $isCleared = false): self {
        $this->initContextData();
        $this->contextData['financials'] = ['total' => $total, 'is_cleared' => $isCleared];
        return $this;
    }

    public function __toString(): string
    {
        return sprintf('%s (%s)', $this->guestName ?? 'Sin Nombre', $this->guestPhone ?? 'Sin Teléfono');
    }

    /**
     * Por dónde se reconoce a esta persona: teléfonos, correos.
     *
     * La identidad vivía aplastada en `$guestPhone` —un valor, un canal— y por eso el correo
     * no tenía dónde ir. Ver {@see MessageIdentidad}.
     *
     * @var Collection<int, MessageIdentidad>
     */
    #[Groups(['conversation:read'])]
    #[ORM\OneToMany(mappedBy: 'conversacion', targetEntity: MessageIdentidad::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $identidades;

    /** @return Collection<int, MessageIdentidad> */
    public function getIdentidades(): Collection { return $this->identidades; }

    /**
     * Añade un identificador si no lo tenía ya.
     *
     * Idempotente a propósito: se llama en cada mensaje entrante, y comprobarlo aquí evita que
     * cada llamador tenga que acordarse.
     */
    public function addIdentidad(MessageIdentidad $identidad): self
    {
        foreach ($this->identidades as $existente) {
            if ($existente->getTipo() === $identidad->getTipo() && $existente->getValor() === $identidad->getValor()) {
                return $this;
            }
        }

        $this->identidades->add($identidad);
        $identidad->setConversacion($this);

        return $this;
    }

    // Los ASUNTOS del hilo NO cuelgan de aquí, y es deliberado desde el 20/08/2026.
    //
    // Había una colección tipada a `PmsConversacionEnlace` y un `use` del PMS en este archivo:
    // el núcleo de mensajería conocía un dominio. Con eso, añadir Travel costaba la entidad,
    // otra colección, otro `use` y otra línea en `getEnlaces()` — y un cliente de hotel que
    // además compra tours multiplicaba el problema.
    //
    // Ahora cada dominio los aporta por `ProveedorDeEnlacesInterface` y quien los junta es
    // `EnlacesDeConversacion`. Enchufar un dominio es UNA clase. Y de paso queda donde debía:
    // una entidad no consulta, y reunir lo de varios dominios exige preguntar a cada uno.
}
