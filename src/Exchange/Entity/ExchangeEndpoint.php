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
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entidad ExchangeEndpoint.
 * Define los puntos de acceso técnicos para las APIs externas.
 */
#[ORM\Entity(repositoryClass: ExchangeEndpointRepository::class)]
#[ORM\Table(name: 'exchange_exchange_endpoint')]
#[ORM\UniqueConstraint(name: 'uq_provider_accion', columns: ['provider', 'accion'])]
// ✅ Validación UI: Atrapa el error de duplicados en el formulario de EasyAdmin
#[UniqueEntity(
    fields: ['provider', 'accion'],
    message: 'Este proveedor ya tiene registrada una acción con este slug identificador.'
)]
#[ORM\HasLifecycleCallbacks]
class ExchangeEndpoint implements EndpointInterface
{
    use IdTrait;
    use TimestampTrait;

    #[ORM\Column(type: 'string', length: 50, enumType: ConnectivityProvider::class)]
    #[Assert\NotNull(message: 'Debe seleccionar un proveedor de conectividad.')]
    private ?ConnectivityProvider $provider = null;

    #[ORM\Column(type: 'string', length: 150)]
    #[Assert\NotBlank(message: 'El nombre descriptivo es obligatorio.')]
    #[Assert\Length(max: 150, maxMessage: 'El nombre no puede superar los 150 caracteres.')]
    private ?string $nombre = null;

    /** Slug técnico (ej: 'GET_TOKEN', 'POST_BOOKINGS') */
    #[ORM\Column(type: 'string', length: 50)]
    #[Assert\NotBlank(message: 'La acción lógica (slug) es obligatoria.')]
    #[Assert\Length(max: 50, maxMessage: 'El slug no puede superar los 50 caracteres.')]
    #[Assert\Regex(
        pattern: '/^[A-Z0-9_]+$/',
        message: 'La acción debe contener solo letras mayúsculas, números y guiones bajos (ej. SEND_MESSAGE).'
    )]
    private ?string $accion = null;

    /**
     * Ruta de la API o URL completa.
     * Mantenido como 'endpoint' para no romper Processors.
     */
    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank(message: 'La ruta (endpoint) es obligatoria.')]
    #[Assert\Length(max: 255)]
    private ?string $endpoint = null;

    /**
     * Verbo HTTP (GET, POST, PUT, DELETE).
     * Mantenido como 'metodo' para no romper Processors.
     */
    #[ORM\Column(type: 'string', length: 10)]
    #[Assert\NotBlank(message: 'El método HTTP es obligatorio.')]
    #[Assert\Choice(
        choices: ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'],
        message: 'Seleccione un método HTTP válido (GET, POST, PUT, DELETE, PATCH).'
    )]
    private string $metodo = 'POST';

    #[ORM\Column(type: 'string', length: 10, options: ['default' => 'v2'])]
    #[Assert\NotBlank(message: 'La versión de la API es obligatoria.')]
    #[Assert\Length(max: 10)]
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
        // Se asegura que la acción se guarde siempre en mayúsculas
        $this->accion = strtoupper($accion);
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
        $this->metodo = strtoupper($metodo);
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