<?php

declare(strict_types=1);

namespace App\Exchange\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Exchange\Service\Contract\ChannelConfigInterface;
use App\Message\Entity\WhatsappMetaSendQueue;
use App\Pms\Entity\PmsEstablecimiento;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity]
#[ORM\Table(name: 'exchange_meta_config')]
#[ORM\HasLifecycleCallbacks]
class MetaConfig implements ChannelConfigInterface
{
    use IdTrait;
    use TimestampTrait;

    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $nombre = null;

    #[ORM\Column(type: 'boolean', nullable: false, options: ['default' => true])]
    private bool $activo = true;

    #[ORM\Column(type: 'string', length: 255, nullable: true, options: ['default' => 'https://graph.facebook.com'])]
    private ?string $baseUrl = 'https://graph.facebook.com';

    #[ORM\Column(type: 'json')]
    private array $credentials = [];

    /**
     * @var Collection<int, WhatsappMetaSendQueue>
     */
    #[ORM\OneToMany(mappedBy: 'config', targetEntity: WhatsappMetaSendQueue::class, cascade: ['persist', 'remove'])]
    private Collection $whatsappMetaSendQueues;

    /**
     * Colección de establecimientos PMS vinculados a esta configuración de Meta.
     * Esta es la relación inversa (OneToMany) mapeada desde PmsEstablecimiento.
     *
     * @var Collection<int, PmsEstablecimiento>
     */
    #[ORM\OneToMany(mappedBy: 'metaConfig', targetEntity: PmsEstablecimiento::class)]
    private Collection $establecimientos;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->whatsappMetaSendQueues = new ArrayCollection();
        $this->establecimientos = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->nombre ?? ('WhatsappMeta Config ' . $this->getId());
    }

    // =========================================================================
    // IMPLEMENTACIÓN DE ChannelConfigInterface
    // =========================================================================

    /**
     * Retorna el alias del proveedor que debe procesar esta configuración
     * Se utiliza para seleccionar el cliente.
     * Ejemplo: 'beds24', 'meta', 'booking'.
     */
    public function getProviderName(): string
    {
        return 'meta';
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
     * @return Collection<int, WhatsappMetaSendQueue>
     */
    public function getWhatsappMetaSendQueues(): Collection
    {
        return $this->whatsappMetaSendQueues;
    }

    public function addWhatsappMetaSendQueue(WhatsappMetaSendQueue $queue): self
    {
        if (!$this->whatsappMetaSendQueues->contains($queue)) {
            $this->whatsappMetaSendQueues->add($queue);
            // Lado propietario de la relación (owning side)
            if ($queue->getConfig() !== $this) {
                $queue->setConfig($this);
            }
        }

        return $this;
    }

    public function removeMetaSendQueue(WhatsappMetaSendQueue $queue): self
    {
        if ($this->whatsappMetaSendQueues->removeElement($queue)) {
            // Establecer el lado propietario a null (si no cambió ya)
            if ($queue->getConfig() === $this) {
                $queue->setConfig(null);
            }
        }

        return $this;
    }

    /**
     * Obtiene la colección de establecimientos asociados a esta configuración.
     *
     * @return Collection<int, PmsEstablecimiento>
     */
    public function getEstablecimientos(): Collection
    {
        return $this->establecimientos;
    }

    /**
     * Asocia un establecimiento a esta configuración de Meta.
     * Mantiene la consistencia bidireccional estableciendo esta configuración en el establecimiento.
     *
     * @param PmsEstablecimiento $establecimiento El establecimiento a vincular
     * @return static
     */
    public function addEstablecimiento(PmsEstablecimiento $establecimiento): static
    {
        if (!$this->establecimientos->contains($establecimiento)) {
            $this->establecimientos->add($establecimiento);
            $establecimiento->setMetaConfig($this);
        }

        return $this;
    }

    /**
     * Desvincula un establecimiento de esta configuración de Meta.
     * Mantiene la consistencia bidireccional anulando la configuración en el establecimiento si aún apunta a esta instancia.
     *
     * @param PmsEstablecimiento $establecimiento El establecimiento a desvincular
     * @return static
     */
    public function removeEstablecimiento(PmsEstablecimiento $establecimiento): static
    {
        if ($this->establecimientos->removeElement($establecimiento)) {
            // set the owning side to null (unless already changed)
            if ($establecimiento->getMetaConfig() === $this) {
                $establecimiento->setMetaConfig(null);
            }
        }

        return $this;
    }
}