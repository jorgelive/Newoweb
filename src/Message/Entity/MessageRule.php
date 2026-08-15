<?php

declare(strict_types=1);

namespace App\Message\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Message\Contract\ConversationMilestoneInterface;
use App\Message\Contract\MessageContextInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
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
    #[Assert\Choice(choices: [
        ConversationMilestoneInterface::CREATED,
        ConversationMilestoneInterface::START,
        ConversationMilestoneInterface::END,
        ConversationMilestoneInterface::EXPECTED_ARRIVAL,
        ConversationMilestoneInterface::CANCELLED,
    ], message: 'El hito de referencia seleccionado no es válido.')]
    private string $milestone = ConversationMilestoneInterface::START;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $offsetMinutes = 0;

    // =========================================================================
    // 3. LOS FILTROS DE SEGMENTACIÓN (Agnósticos)
    // =========================================================================

    /** @var list<string>|null Códigos de canal: `booking`, `airbnb`, `directo`… */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $allowedSources = [];

    /** @var list<string>|null Identificadores de agencia mayorista. */
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

        return $this->matchesSegmentation($context->getOrigin(), $context->getAgencyId());
    }

    /**
     * ÚNICA fuente de verdad de los filtros de segmentación.
     *
     * Existe en esta forma —tomando escalares en vez de un MessageContextInterface— porque
     * el MessageRuleEngine evalúa contra la MessageConversation ya persistida, no contra el
     * adaptador de contexto. Antes cada lado tenía su propia copia del filtro y las dos se
     * desincronizaron: el motor ignoraba `allowedAgencies` por completo.
     *
     * Ambos filtros son de INCLUSIÓN: vacío significa "sin restricción"; con valores, el dato
     * debe estar en la lista. Un $agency nulo nunca satisface una lista de agencias no vacía.
     */
    public function matchesSegmentation(?string $origin, ?string $agency): bool
    {
        $allowedSources = $this->getAllowedSources();
        if (!empty($allowedSources) && !in_array($origin, $allowedSources, true)) {
            return false;
        }

        $allowedAgencies = $this->getAllowedAgencies();
        if (!empty($allowedAgencies) && ($agency === null || !in_array($agency, $allowedAgencies, true))) {
            return false;
        }

        return true;
    }

    // =========================================================================
    // GETTERS Y SETTERS
    // =========================================================================

    public function getId(): ?Uuid { return $this->id; }

    public function getName(): ?string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getContextType(): string { return $this->contextType; }
    public function setContextType(string $contextType): self { $this->contextType = $contextType; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): self { $this->isActive = $isActive; return $this; }

    public function getTemplate(): ?MessageTemplate { return $this->template; }
    public function setTemplate(?MessageTemplate $template): self { $this->template = $template; return $this; }

    public function getMilestone(): string { return $this->milestone; }

    public function setMilestone(string $milestone): self {
        $validMilestones = [
            ConversationMilestoneInterface::CREATED,
            ConversationMilestoneInterface::START,
            ConversationMilestoneInterface::END,
            ConversationMilestoneInterface::EXPECTED_ARRIVAL,
            ConversationMilestoneInterface::CANCELLED,
        ];

        if (!in_array($milestone, $validMilestones, true)) {
            throw new InvalidArgumentException(sprintf(
                'El hito "%s" no es válido. Debe ser una de las constantes definidas en %s.',
                $milestone,
                ConversationMilestoneInterface::class
            ));
        }

        $this->milestone = $milestone;
        return $this;
    }

    public function getOffsetMinutes(): int { return $this->offsetMinutes; }
    public function setOffsetMinutes(int $offsetMinutes): self { $this->offsetMinutes = $offsetMinutes; return $this; }

    /** @return Collection<int, MessageChannel> */
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

    /** @return list<string> */
    public function getAllowedSources(): array { return $this->allowedSources ?? []; }
    /** @param list<string>|null $allowedSources */
    public function setAllowedSources(?array $allowedSources): self { $this->allowedSources = $allowedSources; return $this; }

    /** @return list<string> */
    public function getAllowedAgencies(): array { return $this->allowedAgencies ?? []; }
    /** @param list<string>|null $allowedAgencies */
    public function setAllowedAgencies(?array $allowedAgencies): self { $this->allowedAgencies = $allowedAgencies; return $this; }
}