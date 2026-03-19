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

/**
 * Entidad MetaConfig.
 * Gestiona la configuración de conexión con la Graph API de Meta (WhatsApp Cloud API).
 */
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

    /**
     * Almacena credenciales sensibles: apiKey (token), wabaId, verifyToken, etc.
     */
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

    /*
     * -------------------------------------------------------------------------
     * IMPLEMENTACIÓN ChannelConfigInterface
     * -------------------------------------------------------------------------
     */

    public function getProviderName(): string
    {
        return 'meta';
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl ?? 'https://graph.facebook.com';
    }

    public function isActivo(): bool
    {
        return $this->activo;
    }

    /*
     * -------------------------------------------------------------------------
     * ACCESO DINÁMICO A CREDENCIALES (Tipado)
     * -------------------------------------------------------------------------
     */

    /**
     * Retorna el System User Access Token (Token Permanente).
     */
    public function getApiKey(): ?string
    {
        return $this->getCredential('apiKey');
    }

    /**
     * Retorna el WhatsApp Business Account ID (Necesario para Sincronizar Plantillas).
     */
    public function getWabaId(): ?string
    {
        return $this->getCredential('wabaId');
    }

    /**
     * Retorna el token secreto para la validación del Webhook (Handshake).
     */
    public function getVerifyToken(): ?string
    {
        return $this->getCredential('verifyToken');
    }

    public function getCredential(string $key): mixed
    {
        return $this->credentials[$key] ?? null;
    }

    /*
     * -------------------------------------------------------------------------
     * GETTERS Y SETTERS EXPLÍCITOS
     * -------------------------------------------------------------------------
     */

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

    public function getBaseUrlRaw(): ?string
    {
        return $this->baseUrl;
    }

    public function setBaseUrl(?string $baseUrl): self
    {
        $this->baseUrl = $baseUrl;
        return $this;
    }

    public function getCredentials(): array
    {
        return $this->credentials;
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

    /*
     * -------------------------------------------------------------------------
     * GESTIÓN DE COLECCIONES
     * -------------------------------------------------------------------------
     */

    /** @return Collection<int, WhatsappMetaSendQueue> */
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

    public function removeWhatsappMetaSendQueue(WhatsappMetaSendQueue $queue): self
    {
        if ($this->whatsappMetaSendQueues->removeElement($queue)) {
            if ($queue->getConfig() === $this) {
                $queue->setConfig(null);
            }
        }
        return $this;
    }

    /** @return Collection<int, PmsEstablecimiento> */
    public function getEstablecimientos(): Collection
    {
        return $this->establecimientos;
    }

    public function addEstablecimiento(PmsEstablecimiento $establecimiento): self
    {
        if (!$this->establecimientos->contains($establecimiento)) {
            $this->establecimientos->add($establecimiento);
            $establecimiento->setMetaConfig($this);
        }
        return $this;
    }

    public function removeEstablecimiento(PmsEstablecimiento $establecimiento): self
    {
        if ($this->establecimientos->removeElement($establecimiento)) {
            if ($establecimiento->getMetaConfig() === $this) {
                $establecimiento->setMetaConfig(null);
            }
        }
        return $this;
    }
}