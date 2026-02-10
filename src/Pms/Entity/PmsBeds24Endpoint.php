<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use App\Exchange\Service\Contract\EndpointInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Entidad PmsBeds24Endpoint.
 * Define los puntos de acceso técnicos para la API de Beds24 (v1 y v2).
 * * CRÍTICO: Se mantienen los nombres 'endpoint' y 'metodo' para compatibilidad
 * con la lógica de los Services de sincronización.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pms_beds24_endpoint')]
#[ORM\HasLifecycleCallbacks]
class PmsBeds24Endpoint implements EndpointInterface
{
    use IdTrait;
    use TimestampTrait;

    #[ORM\Column(type: 'string', length: 150)]
    private ?string $nombre = null;

    /** Slug técnico único (ej: 'GET_TOKEN', 'POST_BOOKINGS') */
    #[ORM\Column(type: 'string', length: 50, unique: true)]
    private ?string $accion = null;

    /** * Ruta de la API o URL completa.
     * Mantenido como 'endpoint' para no romper Processors.
     */
    #[ORM\Column(type: 'string', length: 255)]
    private ?string $endpoint = null;

    /** * Verbo HTTP (GET, POST, PUT, DELETE).
     * Mantenido como 'metodo' para no romper Processors.
     */
    #[ORM\Column(type: 'string', length: 10)]
    private string $metodo = 'POST';

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

        $this->id = Uuid::v7();
    }

    public function __toString(): string
    {
        return sprintf('%s [%s]', $this->nombre, $this->accion);
    }

    /* * -------------------------------------------------------------------------
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

    public function getAccion(): ?string
    {
        return $this->accion;
    }

    public function setAccion(string $accion): self
    {
        $this->accion = $accion;
        return $this;
    }

    public function getEndpoint(): ?string
    {
        return $this->endpoint;
    }

    public function setEndpoint(?string $endpoint): self
    {
        $this->endpoint = $endpoint;
        return $this;
    }

    public function getMetodo(): string
    {
        return $this->metodo;
    }

    public function setMetodo(string $metodo): self
    {
        $this->metodo = $metodo;
        return $this;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function setVersion(string $version): self
    {
        $this->version = $version;
        return $this;
    }

    public function getDescripcion(): ?string
    {
        return $this->descripcion;
    }

    public function setDescripcion(?string $descripcion): self
    {
        $this->descripcion = $descripcion;
        return $this;
    }

    public function isActivo(): bool
    {
        return $this->activo;
    }

    public function setActivo(bool $activo): self
    {
        $this->activo = $activo;
        return $this;
    }

    /** * @return Collection<int, PmsRatesPushQueue>
     */
    public function getRatesQueues(): Collection
    {
        return $this->ratesQueues;
    }

    public function addRatesQueue(PmsRatesPushQueue $ratesQueue): self
    {
        if (!$this->ratesQueues->contains($ratesQueue)) {
            $this->ratesQueues->add($ratesQueue);
            $ratesQueue->setEndpoint($this);
        }
        return $this;
    }

    /** * @return Collection<int, PmsBookingsPushQueue>
     */
    public function getBookingsPushQueues(): Collection
    {
        return $this->bookingsPushQueues;
    }

    public function addBookingsPushQueue(PmsBookingsPushQueue $pushQueue): self
    {
        if (!$this->bookingsPushQueues->contains($pushQueue)) {
            $this->bookingsPushQueues->add($pushQueue);
            $pushQueue->setEndpoint($this);
        }
        return $this;
    }

    /** * @return Collection<int, PmsBookingsPullQueue>
     */
    public function getPullQueueJobs(): Collection
    {
        return $this->pullQueueJobs;
    }

    public function addPullQueueJob(PmsBookingsPullQueue $pullJob): self
    {
        if (!$this->pullQueueJobs->contains($pullJob)) {
            $this->pullQueueJobs->add($pullJob);
            $pullJob->setEndpoint($this);
        }
        return $this;
    }
}