<?php

declare(strict_types=1);

namespace App\Message\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Exchange\Service\Contract\EndpointInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity]
#[ORM\Table(name: 'msg_gupshup_endpoint')]
#[ORM\HasLifecycleCallbacks]
class GupshupEndpoint implements EndpointInterface
{
    use IdTrait;
    use TimestampTrait;

    #[ORM\Column(type: 'string', length: 150)]
    private ?string $nombre = null;

    #[ORM\Column(type: 'string', length: 50, unique: true)]
    private ?string $accion = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $endpoint = null;

    #[ORM\Column(type: 'string', length: 10, options: ['default' => 'POST'])]
    private string $metodo = 'POST';

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $activo = true;

    /**
     * @var Collection<int, WhatsappGupshupSendQueue>
     */
    #[ORM\OneToMany(mappedBy: 'endpoint', targetEntity: WhatsappGupshupSendQueue::class)]
    private Collection $whatsappGupshupSendQueues;

    public function __construct()
    {
        $this->whatsappGupshupSendQueues = new ArrayCollection();
        $this->id = Uuid::v7();
    }

    public function __toString(): string
    {
        return sprintf('%s [%s]', $this->nombre ?? 'Sin Nombre', $this->accion ?? 'N/A');
    }

    // =========================================================================
    // IMPLEMENTACIÓN DE EndpointInterface
    // =========================================================================

    public function getAccion(): ?string
    {
        return $this->accion;
    }

    public function getEndpoint(): ?string
    {
        return $this->endpoint;
    }

    public function getMetodo(): string
    {
        return $this->metodo;
    }

    public function isActivo(): bool
    {
        return $this->activo;
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

    public function setAccion(string $accion): self
    {
        $this->accion = $accion;
        return $this;
    }

    public function setEndpoint(?string $endpoint): self
    {
        $this->endpoint = $endpoint;
        return $this;
    }

    public function setMetodo(string $metodo): self
    {
        $this->metodo = strtoupper($metodo); // Normalizamos a mayúsculas por seguridad
        return $this;
    }

    public function setActivo(bool $activo): self
    {
        $this->activo = $activo;
        return $this;
    }

    // =========================================================================
    // GESTIÓN DE LA COLECCIÓN (WhatsappGupshupSendQueue)
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
            // Lado propietario de la relación
            if ($queue->getEndpoint() !== $this) {
                $queue->setEndpoint($this);
            }
        }

        return $this;
    }

    public function removeWhatsappGupshupSendQueue(WhatsappGupshupSendQueue $queue): self
    {
        if ($this->whatsappGupshupSendQueues->removeElement($queue)) {
            // Establecer el lado propietario a null si aún apunta a este endpoint
            if ($queue->getEndpoint() === $this) {
                $queue->setEndpoint(null);
            }
        }

        return $this;
    }
}