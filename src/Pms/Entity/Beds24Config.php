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
    #[ORM\OneToMany(mappedBy: 'beds24Config', targetEntity: PmsUnidadBeds24Map::class, orphanRemoval: true)]
    private Collection $unidadMaps;

    /**
     * Lado inverso para PullQueue.
     * @var Collection<int, PmsBookingsPullQueue>
     */
    #[ORM\OneToMany(mappedBy: 'beds24Config', targetEntity: PmsBookingsPullQueue::class)]
    private Collection $pullQueueJobs;

    /**
     * Lado inverso para Rates (Entidad Plana).
     * @var Collection<int, PmsRatesPushQueue>
     */
    #[ORM\OneToMany(mappedBy: 'beds24Config', targetEntity: PmsRatesPushQueue::class)]
    private Collection $ratesQueues;

    public function __construct()
    {
        $this->unidadMaps = new ArrayCollection();
        $this->pullQueueJobs = new ArrayCollection();
        $this->ratesQueues = new ArrayCollection();
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
            $map->setBeds24Config($this);
        }
        return $this;
    }

    public function removeUnidadMap(PmsUnidadBeds24Map $map): self
    {
        if ($this->unidadMaps->removeElement($map)) {
            if ($map->getBeds24Config() === $this) { $map->setBeds24Config(null); }
        }
        return $this;
    }

    /** @return Collection<int, PmsBookingsPullQueue> */
    public function getPullQueueJobs(): Collection
    {
        return $this->pullQueueJobs;
    }

    public function addPullQueueJob(PmsBookingsPullQueue $job): self
    {
        if (!$this->pullQueueJobs->contains($job)) {
            $this->pullQueueJobs->add($job);
            $job->setBeds24Config($this);
        }
        return $this;
    }

    public function removePullQueueJob(PmsBookingsPullQueue $job): self
    {
        if ($this->pullQueueJobs->removeElement($job)) {
            if ($job->getBeds24Config() === $this) { $job->setBeds24Config(null); }
        }
        return $this;
    }

    /** @return Collection<int, PmsRatesPushQueue> */
    public function getRatesQueues(): Collection
    {
        return $this->ratesQueues;
    }

    public function addRatesQueue(PmsRatesPushQueue $rq): self
    {
        if (!$this->ratesQueues->contains($rq)) {
            $this->ratesQueues->add($rq);
            $rq->setBeds24Config($this);
        }
        return $this;
    }

    public function removeRatesQueue(PmsRatesPushQueue $rq): self
    {
        if ($this->ratesQueues->removeElement($rq)) {
            if ($rq->getBeds24Config() === $this) { $rq->setBeds24Config(null); }
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
}