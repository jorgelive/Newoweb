<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Exchange\Service\Contract\ChannelConfigInterface;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Entidad Beds24Config.
 * Gestiona la configuración de conexión con la API de Beds24 (V2).
 * Implementa UUID como identificador primario.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pms_beds24_config')]
#[ORM\HasLifecycleCallbacks]
class Beds24Config implements ChannelConfigInterface
{
    /**
     * Gestión de Identificador UUID (BINARY 16).
     */
    use IdTrait;

    /**
     * Gestión de auditoría temporal (DateTimeImmutable).
     */
    use TimestampTrait;

    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $nombre = null;

    #[ORM\Column(type: 'string', length: 512, nullable: true)]
    private ?string $refreshToken = null;

    #[ORM\Column(type: 'string', length: 512, nullable: true)]
    private ?string $authToken = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $authTokenExpiresAt = null;

    #[ORM\Column(type: 'boolean', nullable: false, options: ['default' => true])]
    private ?bool $activo = true;

    #[ORM\Column(type: 'string', length: 64, unique: true, nullable: true)]
    private ?string $webhookToken = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true, options: ['default' => 'https://api.beds24.com/v2'])]
    private ?string $baseUrl = 'https://api.beds24.com/v2';

    /**
     * Relación con mapeos de unidad.
     * @var Collection<int, PmsUnidadBeds24Map>
     */
    #[ORM\OneToMany(mappedBy: 'config', targetEntity: PmsUnidadBeds24Map::class, orphanRemoval: true)]
    private Collection $unidadMaps;

    /** @var Collection<int, PmsBookingsPushQueue> */
    #[ORM\OneToMany(mappedBy: 'config', targetEntity: PmsBookingsPushQueue::class)]
    private Collection $bookingsPushQueues;

    /**
     * Lado inverso para PullQueue.
     * @var Collection<int, PmsBookingsPullQueue>
     */
    #[ORM\OneToMany(mappedBy: 'config', targetEntity: PmsBookingsPullQueue::class)]
    private Collection $bookingsPullQueues;

    /**
     * Lado inverso para Rates (Entidad Plana).
     * @var Collection<int, PmsRatesPushQueue>
     */
    #[ORM\OneToMany(mappedBy: 'config', targetEntity: PmsRatesPushQueue::class)]
    private Collection $ratesPushQueues;

    public function __construct()
    {
        $this->unidadMaps = new ArrayCollection();
        $this->bookingsPullQueues = new ArrayCollection();
        $this->ratesPushQueues = new ArrayCollection();

        $this->id = Uuid::v7();
    }

    /*
     * -------------------------------------------------------------------------
     * IMPLEMENTACIÓN ChannelConfigInterface
     * -------------------------------------------------------------------------
     */

    public function getProviderName(): string
    {
        return 'beds24';
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl ?? 'https://api.beds24.com/v2';
    }

    public function isActivo(): ?bool
    {
        return $this->activo;
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

    public function getRefreshToken(): ?string
    {
        return $this->refreshToken;
    }

    public function setRefreshToken(?string $refreshToken): self
    {
        $this->refreshToken = $refreshToken;
        return $this;
    }

    public function getAuthToken(): ?string
    {
        return $this->authToken;
    }

    public function setAuthToken(?string $authToken): self
    {
        $this->authToken = $authToken;
        return $this;
    }

    public function getAuthTokenExpiresAt(): ?DateTimeInterface
    {
        return $this->authTokenExpiresAt;
    }

    public function setAuthTokenExpiresAt(?DateTimeInterface $expiresAt): self
    {
        $this->authTokenExpiresAt = $expiresAt;
        return $this;
    }

    public function setActivo(?bool $activo): self
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

    public function getWebhookToken(): ?string
    {
        return $this->webhookToken;
    }

    public function setWebhookToken(?string $token): self
    {
        $this->webhookToken = $token;
        return $this;
    }

    /** @return Collection<int, PmsUnidadBeds24Map> */
    public function getUnidadMaps(): Collection
    {
        return $this->unidadMaps;
    }

    public function addUnidadMap(PmsUnidadBeds24Map $map): self
    {
        if (!$this->unidadMaps->contains($map)) {
            $this->unidadMaps->add($map);
            $map->setConfig($this);
        }
        return $this;
    }

    public function removeUnidadMap(PmsUnidadBeds24Map $map): self
    {
        if ($this->unidadMaps->removeElement($map)) {
            if ($map->getConfig() === $this) { $map->setConfig(null); }
        }
        return $this;
    }

    /** @return Collection<int, PmsBookingsPullQueue> */
    public function getBookingsPullQueues(): Collection
    {
        return $this->bookingsPullQueues;
    }

    public function addBookingsPullQueue(PmsBookingsPullQueue $job): self
    {
        if (!$this->bookingsPullQueues->contains($job)) {
            $this->bookingsPullQueues->add($job);
            $job->setConfig($this);
        }
        return $this;
    }

    public function removeBookingsPullQueue(PmsBookingsPullQueue $job): self
    {
        if ($this->bookingsPullQueues->removeElement($job)) {
            if ($job->getConfig() === $this) { $job->setConfig(null); }
        }
        return $this;
    }

    /** @return Collection<int, PmsRatesPushQueue> */
    public function getRatesPushQueues(): Collection
    {
        return $this->ratesPushQueues;
    }

    public function addRatesPushQueue(PmsRatesPushQueue $rq): self
    {
        if (!$this->ratesPushQueues->contains($rq)) {
            $this->ratesPushQueues->add($rq);
            $rq->setConfig($this);
        }
        return $this;
    }

    public function removeRatesPushQueue(PmsRatesPushQueue $rq): self
    {
        if ($this->ratesPushQueues->removeElement($rq)) {
            if ($rq->getConfig() === $this) { $rq->setConfig(null); }
        }
        return $this;
    }

    /**
     * Representación textual de la configuración.
     */
    public function __toString(): string
    {
        return $this->nombre ?? ('Config (UUID) ' . $this->getId());
    }

    /** * @return Collection<int, PmsBookingsPushQueue>
     */
    public function getBookingsPushQueues(): Collection
    {
        return $this->bookingsPushQueues;
    }

    public function addBookingsPushQueue(PmsBookingsPushQueue $bookingsPushQueue): self
    {
        if (!$this->bookingsPushQueues->contains($bookingsPushQueue)) {
            $this->bookingsPushQueues->add($bookingsPushQueue);
            $bookingsPushQueue->setConfig($this);
        }
        return $this;
    }
}