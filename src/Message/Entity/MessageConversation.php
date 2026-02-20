<?php

declare(strict_types=1);

namespace App\Message\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Message\Contract\MessageContextInterface; // 🔥 IMPORTANTE
use App\Pms\Entity\PmsReserva;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity]
#[ORM\Table(name: 'message_conversation')]
#[ORM\HasLifecycleCallbacks]
class MessageConversation
{
    use IdTrait;
    use TimestampTrait;

    public const string STATUS_OPEN   = 'open';
    public const string STATUS_CLOSED = 'closed';
    public const string STATUS_ARCHIVED = 'archived';

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_OPEN])]
    private string $status = self::STATUS_OPEN;

    // 🔥 Cambio de nombre, pero forzando el column name para no romper producción
    #[ORM\ManyToOne(targetEntity: PmsReserva::class)]
    #[ORM\JoinColumn(name: 'booking_id', nullable: true, onDelete: 'SET NULL')]
    private ?PmsReserva $pmsBooking = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $guestName = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $guestPhone = null;

    /**
     * @var Collection<int, Message>
     */
    #[ORM\OneToMany(mappedBy: 'conversation', targetEntity: Message::class, cascade: ['persist', 'remove'])]
    private Collection $messages;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->messages = new ArrayCollection();
    }

    public function __toString(): string
    {
        return sprintf('Conv %s (%s)', $this->getResolvedName() ?? 'Unknown', $this->status);
    }

    // =========================================================================
    // 🔥 MÉTODOS AGNÓSTICOS DEL CRM 🔥
    // =========================================================================

    /**
     * Devuelve el contexto actual activo (Reserva, y en el futuro, Tour o Lead).
     */
    public function getContext(): ?MessageContextInterface
    {
        return $this->pmsBooking; // ?? $this->tourBooking
    }

    /**
     * Resuelve dinámicamente el teléfono (Prioridad: forzado manual > contexto)
     */
    public function getResolvedPhone(): ?string
    {
        return $this->guestPhone ?? $this->getContext()?->getContextPhone();
    }

    /**
     * Resuelve dinámicamente el nombre (Prioridad: forzado manual > contexto)
     */
    public function getResolvedName(): ?string
    {
        return $this->guestName ?? $this->getContext()?->getContextName();
    }

    // =========================================================================
    // GETTERS Y SETTERS EXPLÍCITOS
    // =========================================================================

    public function getId(): UuidV7
    {
        return $this->id;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getPmsBooking(): ?PmsReserva
    {
        return $this->pmsBooking;
    }

    public function setPmsBooking(?PmsReserva $pmsBooking): self
    {
        $this->pmsBooking = $pmsBooking;
        return $this;
    }

    public function getGuestName(): ?string
    {
        return $this->guestName;
    }

    public function setGuestName(?string $guestName): self
    {
        $this->guestName = $guestName;
        return $this;
    }

    public function getGuestPhone(): ?string
    {
        return $this->guestPhone;
    }

    public function setGuestPhone(?string $guestPhone): self
    {
        $this->guestPhone = $guestPhone;
        return $this;
    }

    /**
     * @return Collection<int, Message>
     */
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    public function addMessage(Message $message): self
    {
        if (!$this->messages->contains($message)) {
            $this->messages->add($message);
            if ($message->getConversation() !== $this) {
                $message->setConversation($this);
            }
        }
        return $this;
    }

    public function removeMessage(Message $message): self
    {
        if ($this->messages->removeElement($message)) {
            if ($message->getConversation() === $this) {
                $message->setConversation(null);
            }
        }
        return $this;
    }
}