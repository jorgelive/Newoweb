<?php

declare(strict_types=1);

namespace App\Message\Entity;

use App\Entity\Maestro\MaestroIdioma;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'msg_conversation')]
#[ORM\HasLifecycleCallbacks]
class MessageConversation
{
    use IdTrait;
    use TimestampTrait;

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_ARCHIVED = 'archived';

    #[ORM\Column(type: 'string', length: 20, options: ['default' => self::STATUS_OPEN])]
    private string $status = self::STATUS_OPEN;

    // --- EL JOIN LÓGICO ---
    #[ORM\Column(type: 'string', length: 50)]
    private string $contextType;

    #[ORM\Column(type: 'string', length: 100)]
    private string $contextId;

    // --- SNAPSHOT DEL CLIENTE ---
    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    private ?string $guestName = null;

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $guestPhone = null;

    // --- IDIOMA VIVO (RELACIÓN FÍSICA) ---
    /**
     * Idioma vivo de la conversación.
     * Nace de la reserva, pero puede evolucionar por IA o manualmente.
     */
    #[ORM\ManyToOne(targetEntity: MaestroIdioma::class)]
    #[ORM\JoinColumn(name: 'idioma_id', referencedColumnName: 'id', nullable: false)]
    private MaestroIdioma $idioma;

    #[ORM\OneToMany(mappedBy: 'conversation', targetEntity: Message::class, cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $messages;

    public function __construct(string $contextType, string $contextId)
    {
        $this->contextType = $contextType;
        $this->contextId = $contextId;
        $this->messages = new ArrayCollection();
        // El UuidV7 se genera automáticamente en el IdTrait
    }

    // --- GETTERS Y SETTERS ---

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }

    public function getContextType(): string { return $this->contextType; }
    public function getContextId(): string { return $this->contextId; }

    public function getGuestName(): ?string { return $this->guestName; }
    public function setGuestName(?string $guestName): self { $this->guestName = $guestName; return $this; }

    public function getGuestPhone(): ?string { return $this->guestPhone; }
    public function setGuestPhone(?string $guestPhone): self { $this->guestPhone = $guestPhone; return $this; }

    public function getIdioma(): MaestroIdioma { return $this->idioma; }
    public function setIdioma(MaestroIdioma $idioma): self { $this->idioma = $idioma; return $this; }

    public function getMessages(): Collection { return $this->messages; }
    public function addMessage(Message $message): self {
        if (!$this->messages->contains($message)) {
            $this->messages->add($message);
            $message->setConversation($this);
        }
        return $this;
    }

    public function removeMessage(Message $message): self {
        if ($this->messages->removeElement($message)) {
            // set the owning side to null (unless already changed)
            if ($message->getConversation() === $this) {
                $message->setConversation(null);
            }
        }
        return $this;
    }

    // Método de conveniencia para EasyAdmin / UI
    public function __toString(): string
    {
        return sprintf('%s (%s)', $this->guestName ?? 'Sin Nombre', $this->guestPhone ?? 'Sin Teléfono');
    }
}