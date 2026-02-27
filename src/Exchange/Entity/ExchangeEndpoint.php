<?php

declare(strict_types=1);

namespace App\Exchange\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Exchange\Enum\ConnectivityProvider;
use App\Exchange\Repository\ExchangeEndpointRepository;
use App\Exchange\Service\Contract\EndpointInterface;
use App\Pms\Entity\PmsBookingsPullQueue;
use App\Pms\Entity\PmsBookingsPushQueue;
use App\Pms\Entity\PmsRatesPushQueue;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Entidad ExchangeEndpoint.
 * Define los puntos de acceso técnicos para las APIs externas.
 */
#[ORM\Entity(repositoryClass: ExchangeEndpointRepository::class)]
#[ORM\Table(name: 'exchange_exchange_endpoint')]
// ✅ NUEVO: La combinación de proveedor + acción debe ser única
#[ORM\UniqueConstraint(name: 'uq_provider_accion', columns: ['provider', 'accion'])]
#[ORM\HasLifecycleCallbacks]
class ExchangeEndpoint implements EndpointInterface
{
    use IdTrait;
    use TimestampTrait;

    // ✅ NUEVO: El proveedor de conectividad basado en Enum
    #[ORM\Column(type: 'string', length: 50, enumType: ConnectivityProvider::class)]
    private ?ConnectivityProvider $provider = null;

    #[ORM\Column(type: 'string', length: 150)]
    private ?string $nombre = null;

    /** Slug técnico (ej: 'GET_TOKEN', 'POST_BOOKINGS') */
    // ✅ CORRECCIÓN: Quitamos el "unique: true" de aquí, ahora la unicidad es compuesta
    #[ORM\Column(type: 'string', length: 50)]
    private ?string $accion = null;

    /**
     * Ruta de la API o URL completa.
     * Mantenido como 'endpoint' para no romper Processors.
     */
    #[ORM\Column(type: 'string', length: 255)]
    private ?string $endpoint = null;

    /**
     * Verbo HTTP (GET, POST, PUT, DELETE).
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
    private Collection $ratesPushQueues;

    /** @var Collection<int, PmsBookingsPushQueue> */
    #[ORM\OneToMany(mappedBy: 'endpoint', targetEntity: PmsBookingsPushQueue::class)]
    private Collection $bookingsPushQueues;

    /** @var Collection<int, PmsBookingsPullQueue> */
    #[ORM\OneToMany(mappedBy: 'endpoint', targetEntity: PmsBookingsPullQueue::class)]
    private Collection $bookingsPullQueues;


    public function __construct()
    {
        $this->ratesPushQueues = new ArrayCollection();
        $this->bookingsPushQueues = new ArrayCollection();
        $this->bookingsPullQueues = new ArrayCollection();

        $this->id = Uuid::v7();
    }

    public function __toString(): string
    {
        $providerName = $this->provider ? $this->provider->getLabel() : 'Sin Proveedor';
        return sprintf('%s [%s - %s]', $this->nombre, $providerName, $this->accion);
    }

    /* * -------------------------------------------------------------------------
     * GETTERS Y SETTERS EXPLÍCITOS
     * -------------------------------------------------------------------------
     */

    public function getProvider(): ?ConnectivityProvider
    {
        return $this->provider;
    }

    public function setProvider(?ConnectivityProvider $provider): self
    {
        $this->provider = $provider;
        return $this;
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
    public function getRatesPushQueues(): Collection
    {
        return $this->ratesPushQueues;
    }

    public function addRatesPushQueue(PmsRatesPushQueue $ratesPushQueue): self
    {
        if (!$this->ratesPushQueues->contains($ratesPushQueue)) {
            $this->ratesPushQueues->add($ratesPushQueue);
            $ratesPushQueue->setEndpoint($this);
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
    public function getBookingsPullQueues(): Collection
    {
        return $this->bookingsPullQueues;
    }

    public function addBookingsPullQueue(PmsBookingsPullQueue $pullJob): self
    {
        if (!$this->bookingsPullQueues->contains($pullJob)) {
            $this->bookingsPullQueues->add($pullJob);
            $pullJob->setEndpoint($this);
        }
        return $this;
    }
}