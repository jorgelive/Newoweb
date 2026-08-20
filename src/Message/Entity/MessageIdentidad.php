<?php

declare(strict_types=1);

namespace App\Message\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Message\Enum\IdentidadTipo;
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
}
