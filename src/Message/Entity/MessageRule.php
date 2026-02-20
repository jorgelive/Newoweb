<?php

declare(strict_types=1);

namespace App\Message\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'msg_rule')]
#[ORM\HasLifecycleCallbacks]
class MessageRule
{
    use IdTrait;
    use TimestampTrait;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    private ?string $name = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\ManyToOne(targetEntity: MessageTemplate::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?MessageTemplate $template = null;

    #[ORM\ManyToOne(targetEntity: MessageChannel::class)]
    #[ORM\JoinColumn(name: 'channel_id', referencedColumnName: 'id', nullable: false)]
    private ?MessageChannel $channel = null;

    // =========================================================================
    // LÓGICA DE PROGRAMACIÓN (SCHEDULER)
    // =========================================================================

    /**
     * El nombre del hito que define el contexto (ej: 'start', 'end', 'created')
     */
    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    private string $milestone = 'start';

    /**
     * Minutos relativos al hito.
     * Ej: -1440 = 24 horas antes. 0 = En el momento. 120 = 2 horas después.
     */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $offsetMinutes = 0;

    /**
     * Define a qué tipo de entidad aplica esta regla (ej: 'pms_reserva', 'tour_booking', etc.)
     */
    #[ORM\Column(length: 50, options: ['default' => 'pms_reserva'])]
    private string $contextType = 'pms_reserva';

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function __toString(): string
    {
        return $this->name ?? 'Nueva Regla';
    }

    // Getters y Setters básicos...
    public function getId(): UuidV7 { return $this->id; }

    public function getName(): ?string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): self { $this->isActive = $isActive; return $this; }

    public function getTemplate(): ?MessageTemplate { return $this->template; }
    public function setTemplate(?MessageTemplate $template): self { $this->template = $template; return $this; }

    public function getChannel(): ?MessageChannel { return $this->channel; }
    public function setChannel(?MessageChannel $channel): self { $this->channel = $channel; return $this; }

    public function getMilestone(): string { return $this->milestone; }
    public function setMilestone(string $milestone): self { $this->milestone = $milestone; return $this; }

    public function getOffsetMinutes(): int { return $this->offsetMinutes; }
    public function setOffsetMinutes(int $offsetMinutes): self { $this->offsetMinutes = $offsetMinutes; return $this; }

    public function getContextType(): string { return $this->contextType; }
    public function setContextType(string $contextType): self { $this->contextType = $contextType; return $this; }
}