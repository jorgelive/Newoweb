<?php

declare(strict_types=1);

namespace App\Exchange\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Exchange\Service\Contract\ChannelConfigInterface;
use App\Message\Entity\WhatsappGupshupSendQueue;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity]
#[ORM\Table(name: 'exchange_gupshup_config')]
#[ORM\HasLifecycleCallbacks]
class GupshupConfig implements ChannelConfigInterface
{
    use IdTrait;
    use TimestampTrait;

    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $nombre = null;

    #[ORM\Column(type: 'boolean', nullable: false, options: ['default' => true])]
    private bool $activo = true;

    #[ORM\Column(type: 'string', length: 255, nullable: true, options: ['default' => 'https://api.gupshup.io/'])]
    private ?string $baseUrl = 'https://api.gupshup.io/';

    #[ORM\Column(type: 'json')]
    private array $credentials = [];

    /**
     * @var Collection<int, WhatsappGupshupSendQueue>
     */
    #[ORM\OneToMany(mappedBy: 'config', targetEntity: WhatsappGupshupSendQueue::class, cascade: ['persist', 'remove'])]
    private Collection $whatsappGupshupSendQueues;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->whatsappGupshupSendQueues = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->nombre ?? ('WhatsappGupshup Config ' . $this->getId());
    }

    // =========================================================================
    // IMPLEMENTACIÓN DE ChannelConfigInterface
    // =========================================================================

    public function getProviderName(): string
    {
        return 'gupshup';
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl ?? '';
    }

    public function isActivo(): bool
    {
        return $this->activo;
    }

    public function getCredentials(): array
    {
        return $this->credentials;
    }

    public function getCredential(string $key): mixed
    {
        return $this->credentials[$key] ?? null;
    }

    // =========================================================================
    // GETTERS Y SETTERS EXPLÍCITOS
    // =========================================================================

    public function getId(): UuidV7
    {
        return $this->id;
    }

    public function getNombre(): ?string
    {
        return $this->nombre;
    }

    public function setNombre(?string $nombre): self
    {
        $this->nombre = $nombre;
        return $this;
    }

    public function setActivo(bool $activo): self
    {
        $this->activo = $activo;
        return $this;
    }

    public function setBaseUrl(?string $baseUrl): self
    {
        $this->baseUrl = $baseUrl;
        return $this;
    }

    public function setCredentials(array $credentials): self
    {
        $this->credentials = $credentials;
        return $this;
    }

    public function addCredential(string $key, mixed $value): self
    {
        $this->credentials[$key] = $value;
        return $this;
    }

    // =========================================================================
    // GESTIÓN DE LA COLECCIÓN (Relación OneToMany)
    // =========================================================================

    /**
     * @return Collection<int, WhatsappGupshupSendQueue>
     */
    public function getWhatsappGupshupSendQueues(): Collection
    {
        return $this->whatsappGupshupSendQueues;
    }

    public function addWhatsappGupshupSendQueue(WhatsappGupshupSendQueue $queue): self
    {
        if (!$this->whatsappGupshupSendQueues->contains($queue)) {
            $this->whatsappGupshupSendQueues->add($queue);
            // Lado propietario de la relación (owning side)
            if ($queue->getConfig() !== $this) {
                $queue->setConfig($this);
            }
        }

        return $this;
    }

    public function removeGupshupSendQueue(WhatsappGupshupSendQueue $queue): self
    {
        if ($this->whatsappGupshupSendQueues->removeElement($queue)) {
            // Establecer el lado propietario a null (si no cambió ya)
            if ($queue->getConfig() === $this) {
                $queue->setConfig(null);
            }
        }

        return $this;
    }
}