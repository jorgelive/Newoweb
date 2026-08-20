<?php

declare(strict_types=1);

namespace App\Message\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Message\Enum\IdentidadTipo;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Por dónde se reconoce a la persona de una conversación: un teléfono, un correo.
 *
 * ## Qué problema cierra
 *
 * La identidad del hilo vivía en **una columna**, `MessageConversation::$guestPhone`. Un solo
 * valor y un solo canal: no había dónde poner un segundo teléfono, ni el correo por el que la
 * misma persona contesta. Y como la búsqueda toleraba prefijos con
 * `LIKE '%últimos 8 dígitos'`, el emparejado era **aproximado** — podía casar con otra persona
 * y no dejaba rastro de que la decisión había sido dudosa.
 *
 * Está medido en el propio {@see \App\Message\Contract\ConversacionEnlaceInterface}: **20
 * teléfonos con más de una conversación**, uno con 247 mensajes repartidos en dos hilos.
 *
 * ## La regla
 *
 * `(tipo, valor)` es **único**: un identificador pertenece a un hilo y sólo a uno. Eso es lo que
 * convierte la resolución en determinista y permite quitar el `LIKE`.
 *
 * ⚠️ **Es el otro eje, no el mismo.** Los ASUNTOS —una reserva, un expediente— cuelgan por
 * {@see \App\Message\Contract\ConversacionEnlaceInterface}; esto son los IDENTIFICADORES. Una
 * persona tiene varios de cada uno y son independientes: dos correos y tres reservas.
 *
 * ```
 * Conversación
 *    ├─ identidades  telefono:+51984…  ·  email:nune@…      ← por dónde se le reconoce
 *    └─ enlaces      pms/pms_reserva/…  ·  travel/…          ← de qué se habla
 * ```
 */
#[ORM\Entity]
#[ORM\Table(name: 'msg_identidad')]
#[ORM\UniqueConstraint(name: 'uq_identidad_tipo_valor', columns: ['tipo', 'valor'])]
#[ORM\Index(name: 'idx_identidad_conversacion', columns: ['conversacion_id'])]
#[ORM\HasLifecycleCallbacks]
class MessageIdentidad
{
    use IdTrait;
    use TimestampTrait;

    #[ORM\ManyToOne(targetEntity: MessageConversation::class, inversedBy: 'identidades')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?MessageConversation $conversacion = null;

    #[Groups(['conversation:read'])]
    #[ORM\Column(type: 'string', length: 20, enumType: IdentidadTipo::class)]
    private IdentidadTipo $tipo;

    /** Ya normalizado por {@see IdentidadTipo::normalizar()}. Nunca se guarda en crudo. */
    #[Groups(['conversation:read'])]
    #[ORM\Column(type: 'string', length: 190)]
    private string $valor;

    /**
     * Cómo llegó aquí: `whatsapp`, `beds24`, `migracion`, `manual`.
     *
     * No decide nada; sirve para responder «¿de dónde salió este correo?» cuando alguien
     * pregunte por qué dos personas acabaron en el mismo hilo.
     */
    #[Groups(['conversation:read'])]
    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $origen = null;

    /**
     * El canal está vetado PARA ESTE NÚMERO.
     *
     * ── Por qué aquí y no en la conversación ────────────────────────────────
     * Estaba en `MessageConversation::$whatsappDisabled`, y ahí es de la PERSONA: un número
     * muerto apagaba WhatsApp para todo el hilo —y desde la fusión, para todos sus asuntos—,
     * incluido el número bueno de quien tiene dos. El comando de fusión lo propagaba además
     * por el lado pesimista («si alguno lo estaba, se queda desactivado»).
     *
     * El veto es de un NÚMERO: lo pone Meta rechazando un envío a ese destino concreto.
     *
     * ⚠️ La columna de la conversación **se queda como espejo** y no es un descuido: es entrada
     * de `MessageRuleEngineListener::CAMPOS_CRITICOS`, que lee el change set de Doctrine y sólo
     * ve campos MAPEADOS. Un getter derivado habría dejado de disparar la re-evaluación —y con
     * ella el rescate de los mensajes cancelados por no tener canal— sin un solo error. Mismo
     * trato que `guest_phone`: copia denormalizada, la verdad está aquí.
     */
    #[Groups(['conversation:read'])]
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $bloqueado = false;

    /** El error de Meta que lo vetó, tal cual, para que se pueda juzgar si fue real. */
    #[Groups(['conversation:read'])]
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $bloqueadoMotivo = null;

    /**
     * La salida por defecto cuando la persona tiene varios identificadores del mismo tipo.
     *
     * Hoy todos los hilos tienen un teléfono y ya está, así que no decide nada. Existe porque es
     * lo que permitió quitar `PmsReserva::$telefono2` y su flag: «cuál es el bueno» es de la
     * PERSONA, no de cada reserva, y repetido por reserva se contradice solo.
     */
    #[Groups(['conversation:read'])]
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $principal = false;

    /**
     * Retirada: sigue resolviendo el historial, deja de ser salida. **Es una lápida.**
     *
     * ── Por qué no se borra ─────────────────────────────────────────────────
     * Dos motivos, y el segundo es el que la hace obligatoria:
     *
     * 1. Quien escriba desde el número viejo tiene que seguir cayendo en su hilo. Borrar la fila
     *    parte el historial de esa persona en dos.
     * 2. `MessageConversationFactory::upsertFromContext()` registra los identificadores del
     *    dominio **en cada recálculo**. Si la fila desapareciera, el siguiente pull de Beds24
     *    —que reescribe `telefono` mientras `datosLocked` esté abierto— la resucitaría, y
     *    retirar un número sería imposible. Con la fila presente, `addIdentidad()` deduplica por
     *    `(tipo, valor)` y no vuelve a añadirla.
     *
     * O sea: «no se borra, se marca» deja de ser filosofía y pasa a ser el mecanismo.
     */
    #[Groups(['conversation:read'])]
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $retiradoEn = null;

    public function __construct(IdentidadTipo $tipo, string $valor, ?string $origen = null)
    {
        $this->initializeId();
        $this->tipo = $tipo;
        $this->valor = $tipo->normalizar($valor);
        $this->origen = $origen;
    }

    #[Groups(['conversation:read'])]
    public function getId(): ?Uuid { return $this->id; }

    public function getConversacion(): ?MessageConversation { return $this->conversacion; }
    public function setConversacion(?MessageConversation $v): self { $this->conversacion = $v; return $this; }

    public function getTipo(): IdentidadTipo { return $this->tipo; }

    public function getValor(): string { return $this->valor; }

    public function getOrigen(): ?string { return $this->origen; }

    public function isBloqueado(): bool { return $this->bloqueado; }
    public function getBloqueadoMotivo(): ?string { return $this->bloqueadoMotivo; }

    public function bloquear(string $motivo): self
    {
        $this->bloqueado = true;
        $this->bloqueadoMotivo = $motivo;

        return $this;
    }

    public function desbloquear(): self
    {
        $this->bloqueado = false;
        $this->bloqueadoMotivo = null;

        return $this;
    }

    public function isPrincipal(): bool { return $this->principal; }
    public function setPrincipal(bool $v): self { $this->principal = $v; return $this; }

    public function getRetiradoEn(): ?DateTimeImmutable { return $this->retiradoEn; }

    /** ¿Sigue siendo una salida válida? Una retirada resuelve, pero no se le escribe. */
    public function estaViva(): bool { return $this->retiradoEn === null; }

    /**
     * Levanta la lápida. **Sólo por decisión de una persona.**
     *
     * La retirada frena al DOMINIO, que re-registra los identificadores en cada recálculo sin
     * criterio propio. A quien decide a mano no tiene por qué frenarlo: volver a añadir un
     * número retirado es rectificar, y eso tiene que poder hacerse.
     *
     * No lleva `principal`: revivir un identificador no lo convierte en la salida por defecto,
     * que es otra decisión y se toma aparte.
     */
    public function revivir(): self
    {
        $this->retiradoEn = null;

        return $this;
    }

    /** Retirar es idempotente: la fecha de la PRIMERA retirada es la que cuenta. */
    public function retirar(DateTimeImmutable $cuando): self
    {
        $this->retiradoEn ??= $cuando;
        $this->principal = false;

        return $this;
    }
}
