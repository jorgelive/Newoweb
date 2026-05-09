<?php

declare(strict_types=1);

namespace App\Cotizacion\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;

#[ApiResource(operations: [new Get()], routePrefix: '/sales')]
#[ORM\Entity]
#[ORM\Table(name: 'cotizacion_cotservicio')]
#[ORM\HasLifecycleCallbacks]
class CotizacionCotservicio
{
    use IdTrait;
    use TimestampTrait;

    #[ORM\ManyToOne(targetEntity: Cotizacion::class, inversedBy: 'cotservicios')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Cotizacion $cotizacion = null;

    #[Groups(['cotizacion:read', 'cotizacion:write', 'cotizacion:item:read'])]
    #[ORM\Column(type: 'json')]
    private array $nombreSnapshot = [];

    #[Groups(['cotizacion:read', 'cotizacion:write', 'cotizacion:item:read'])]
    #[ORM\Column(type: 'json')]
    private array $itinerarioNombreSnapshot = [];

    #[Groups(['cotizacion:read', 'cotizacion:write', 'cotizacion:item:read'])]
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $fechaInicioAbsoluta = null;

    /**
     * @var Collection<int, CotizacionCotcomponente>
     */
    #[Groups(['cotizacion:read', 'cotizacion:write', 'cotizacion:item:read'])]
    #[ORM\OneToMany(mappedBy: 'cotservicio', targetEntity: CotizacionCotcomponente::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['fechaHoraInicio' => 'ASC'])]
    private Collection $cotcomponentes;

    /**
     * @var Collection<int, CotizacionSegmento>
     */
    #[Groups(['cotizacion:read', 'cotizacion:write', 'cotizacion:item:read'])]
    #[ORM\OneToMany(mappedBy: 'cotservicio', targetEntity: CotizacionSegmento::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['dia' => 'ASC', 'orden' => 'ASC'])]
    private Collection $cotsegmentos;

    #[Groups(['cotizacion:read', 'cotizacion:write', 'cotizacion:item:read'])]
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $servicioMaestroId = null;

    public function __construct()
    {
        $this->initializeId();
        $this->cotcomponentes = new ArrayCollection();
        $this->cotsegmentos = new ArrayCollection();
    }

    #[Groups(['cotizacion:read', 'cotizacion:item:read'])]
    public function getId(): ?Uuid { return $this->id; }

    #[Groups(['cotizacion:write'])]
    public function setId(Uuid|string $id): self
    {
        $this->id = is_string($id) ? Uuid::fromString($id) : $id;
        return $this;
    }

    public function getCotizacion(): ?Cotizacion { return $this->cotizacion; }
    public function setCotizacion(?Cotizacion $cotizacion): self { $this->cotizacion = $cotizacion; return $this; }

    public function getNombreSnapshot(): array { return $this->nombreSnapshot; }
    public function setNombreSnapshot(array $nombreSnapshot): self { $this->nombreSnapshot = $nombreSnapshot; return $this; }

    public function getItinerarioNombreSnapshot(): array { return $this->itinerarioNombreSnapshot; }
    public function setItinerarioNombreSnapshot(array $itinerarioNombreSnapshot): self { $this->itinerarioNombreSnapshot = $itinerarioNombreSnapshot; return $this; }

    public function getFechaInicioAbsoluta(): ?DateTimeImmutable { return $this->fechaInicioAbsoluta; }
    public function setFechaInicioAbsoluta(?DateTimeImmutable $fechaInicioAbsoluta): self { $this->fechaInicioAbsoluta = $fechaInicioAbsoluta; return $this; }

    public function getCotcomponentes(): Collection { return $this->cotcomponentes; }
    public function addCotcomponente(CotizacionCotcomponente $cotcomponente): self
    {
        if (!$this->cotcomponentes->contains($cotcomponente)) {
            $this->cotcomponentes->add($cotcomponente);
            $cotcomponente->setCotservicio($this);
        }
        return $this;
    }
    public function removeCotcomponente(CotizacionCotcomponente $cotcomponente): self
    {
        if ($this->cotcomponentes->removeElement($cotcomponente)) {
            if ($cotcomponente->getCotservicio() === $this) { $cotcomponente->setCotservicio(null); }
        }
        return $this;
    }

    public function getCotsegmentos(): Collection { return $this->cotsegmentos; }
    public function addCotsegmento(CotizacionSegmento $cotsegmento): self
    {
        if (!$this->cotsegmentos->contains($cotsegmento)) {
            $this->cotsegmentos->add($cotsegmento);
            $cotsegmento->setCotservicio($this);
        }
        return $this;
    }
    public function removeCotsegmento(CotizacionSegmento $cotsegmento): self
    {
        if ($this->cotsegmentos->removeElement($cotsegmento)) {
            if ($cotsegmento->getCotservicio() === $this) { $cotsegmento->setCotservicio(null); }
        }
        return $this;
    }

    public function getServicioMaestroId(): ?string { return $this->servicioMaestroId; }
    public function setServicioMaestroId(?string $servicioMaestroId): self { $this->servicioMaestroId = $servicioMaestroId; return $this; }
}