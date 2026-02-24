<?php

declare(strict_types=1);

namespace App\Message\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Message\Contract\MessageContextInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    private string $contextType = 'pms_reserva';

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\ManyToOne(targetEntity: MessageTemplate::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?MessageTemplate $template = null;

    // =========================================================================
    // 1. EL MEDIO DE SALIDA (Las Tuberías Tecnológicas)
    // =========================================================================

    /** @var Collection<int, MessageChannel> */
    #[ORM\ManyToMany(targetEntity: MessageChannel::class)]
    #[ORM\JoinTable(name: 'msg_rule_target_channels')]
    private Collection $targetCommunicationChannels;

    // =========================================================================
    // 2. LÓGICA DE PROGRAMACIÓN (SCHEDULER)
    // =========================================================================

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    private string $milestone = 'start';

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $offsetMinutes = 0;

    // =========================================================================
    // 3. LOS FILTROS DE SEGMENTACIÓN (Agnósticos)
    // =========================================================================

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $allowedSources = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $allowedAgencies = [];


    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->targetCommunicationChannels = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->name ?? 'Nueva Regla';
    }

    // =========================================================================
    // MAGIA PURA: EVALUACIÓN DE REGLAS
    // =========================================================================

    public function isSatisfiedBy(MessageContextInterface $context): bool
    {
        if (!$this->isActive) {
            return false;
        }

        $attributes = $context->getSegmentationAttributes();

        // 1. EVALUAR FUENTES (OTAs, Directo, etc.)
        $allowedSources = $this->getAllowedSources();
        if (!empty($allowedSources)) {
            $contextSource = $attributes['source'] ?? null;
            if (!in_array($contextSource, $allowedSources, true)) {
                return false;
            }
        }

        // 2. EVALUAR AGENCIAS
        $allowedAgencies = $this->getAllowedAgencies();
        if (!empty($allowedAgencies)) {
            $contextAgency = (string) ($attributes['agency_id'] ?? '');
            if (!in_array($contextAgency, $allowedAgencies, true)) {
                return false;
            }
        }

        return true;
    }

    // =========================================================================
    // GETTERS Y SETTERS
    // =========================================================================

    public function getId(): UuidV7 { return $this->id; }

    public function getName(): ?string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getContextType(): string
    {
        return $this->contextType;
    }

    public function setContextType(string $contextType): self
    {
        $this->contextType = $contextType;
        return $this;
    }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): self { $this->isActive = $isActive; return $this; }

    public function getTemplate(): ?MessageTemplate { return $this->template; }
    public function setTemplate(?MessageTemplate $template): self { $this->template = $template; return $this; }

    public function getMilestone(): string { return $this->milestone; }
    public function setMilestone(string $milestone): self { $this->milestone = $milestone; return $this; }

    public function getOffsetMinutes(): int { return $this->offsetMinutes; }
    public function setOffsetMinutes(int $offsetMinutes): self { $this->offsetMinutes = $offsetMinutes; return $this; }

    public function getTargetCommunicationChannels(): Collection { return $this->targetCommunicationChannels; }
    public function addTargetCommunicationChannel(MessageChannel $channel): self {
        if (!$this->targetCommunicationChannels->contains($channel)) {
            $this->targetCommunicationChannels->add($channel);
        }
        return $this;
    }
    public function removeTargetCommunicationChannel(MessageChannel $channel): self {
        $this->targetCommunicationChannels->removeElement($channel);
        return $this;
    }

    public function getAllowedSources(): array { return $this->allowedSources ?? []; }
    public function setAllowedSources(?array $allowedSources): self { $this->allowedSources = $allowedSources; return $this; }

    public function getAllowedAgencies(): array { return $this->allowedAgencies ?? []; }
    public function setAllowedAgencies(?array $allowedAgencies): self { $this->allowedAgencies = $allowedAgencies; return $this; }
}