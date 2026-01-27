<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entidad PmsBeds24Endpoint.
 * Define los puntos de acceso técnicos para la API de Beds24 (v1 y v2).
 */
#[ORM\Entity]
#[ORM\Table(name: 'pms_beds24_endpoint')]
#[ORM\HasLifecycleCallbacks]
class PmsBeds24Endpoint
{
    use IdTrait;
    use TimestampTrait;

    #[ORM\Column(type: 'string', length: 150)]
    private ?string $nombre = null;

    /** Slug técnico único (ej: 'GET_TOKEN', 'POST_BOOKINGS') */
    #[ORM\Column(type: 'string', length: 50, unique: true)]
    private ?string $accion = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $path = null;

    #[ORM\Column(type: 'string', length: 10)]
    private string $method = 'POST';

    #[ORM\Column(type: 'string', length: 10, options: ['default' => 'v2'])]
    private string $version = 'v2';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $descripcion = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $activo = true;

    /** @var Collection<int, PmsRatesPushQueue> */
    #[ORM\OneToMany(mappedBy: 'endpoint', targetEntity: PmsRatesPushQueue::class)]
    private Collection $ratesQueues;

    /** @var Collection<int, PmsBookingsPushQueue> */
    #[ORM\OneToMany(mappedBy: 'endpoint', targetEntity: PmsBookingsPushQueue::class)]
    private Collection $bookingsPushQueues;

    /** @var Collection<int, PmsBookingsPullQueue> */
    #[ORM\OneToMany(mappedBy: 'endpoint', targetEntity: PmsBookingsPullQueue::class)]
    private Collection $pullQueueJobs;

    public function __construct()
    {
        $this->ratesQueues = new ArrayCollection();
        $this->bookingsPushQueues = new ArrayCollection();
        $this->pullQueueJobs = new ArrayCollection();
    }

    public function __toString(): string
    {
        return sprintf('%s [%s]', $this->nombre, $this->accion);
    }

    /* GETTERS Y SETTERS */

    public function getNombre(): ?string { return $this->nombre; }
    public function setNombre(?string $nombre): self { $this->nombre = $nombre; return $this; }

    public function getAccion(): ?string { return $this->accion; }
    public function setAccion(string $accion): self { $this->accion = $accion; return $this; }

    public function getPath(): ?string { return $this->path; }
    public function setPath(?string $path): self { $this->path = $path; return $this; }

    public function getMethod(): string { return $this->method; }
    public function setMethod(string $method): self { $this->method = $method; return $this; }

    public function getVersion(): string { return $this->version; }
    public function setVersion(string $version): self { $this->version = $version; return $this; }

    public function getDescripcion(): ?string { return $this->descripcion; }
    public function setDescripcion(?string $descripcion): self { $this->descripcion = $descripcion; return $this; }

    public function isActivo(): bool { return $this->activo; }
    public function setActivo(bool $activo): self { $this->activo = $activo; return $this; }

    /** @return Collection<int, PmsRatesPushQueue> */
    public function getRatesQueues(): Collection { return $this->ratesQueues; }

    /** @return Collection<int, PmsBookingsPushQueue> */
    public function getBookingsPushQueues(): Collection { return $this->bookingsPushQueues; }

    /** @return Collection<int, PmsBookingsPullQueue> */
    public function getPullQueueJobs(): Collection { return $this->pullQueueJobs; }
}