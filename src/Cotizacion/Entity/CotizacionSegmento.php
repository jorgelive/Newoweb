<?php

declare(strict_types=1);

namespace App\Cotizacion\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Attribute\AutoTranslate;
use App\Entity\Trait\AutoTranslateControlTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;

#[ApiResource(operations: [new Get()], routePrefix: '/sales')]
#[ORM\Entity]
#[ORM\Table(name: 'cotizacion_segmento')]
#[ORM\HasLifecycleCallbacks]
class CotizacionSegmento
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait;

    #[ORM\ManyToOne(targetEntity: CotizacionCotservicio::class, inversedBy: 'cotsegmentos')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?CotizacionCotservicio $cotservicio = null;

    #[Groups(['cotizacion:read', 'cotizacion:item:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'integer')]
    private int $dia = 1;

    #[Groups(['cotizacion:read', 'cotizacion:item:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'integer')]
    private int $orden = 1;

    #[Groups(['cotizacion:read', 'cotizacion:item:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'date_immutable')]
    private ?DateTimeImmutable $fechaAbsoluta = null;

    #[Groups(['cotizacion:read', 'cotizacion:item:read', 'cotizacion:write'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $nombreSnapshot = [];

    #[Groups(['cotizacion:read', 'cotizacion:item:read', 'cotizacion:write'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'html')]
    #[ORM\Column(type: 'json')]
    private array $contenidoSnapshot = [];

    #[Groups(['cotizacion:read', 'cotizacion:item:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'json')]
    private array $imagenesSnapshot = [];

    #[ORM\OneToMany(mappedBy: 'cotsegmento', targetEntity: CotizacionCotcomponente::class)]
    private Collection $cotcomponentes;

    public function __construct()
    {
        $this->initializeId();
        $this->cotcomponentes = new ArrayCollection();
    }

    #[Groups(['cotizacion:read', 'cotizacion:item:read'])]
    public function getId(): ?Uuid { return $this->id; }

    #[Groups(['cotizacion:write'])]
    public function setId(Uuid|string $id): self
    {
        $this->id = is_string($id) ? Uuid::fromString($id) : $id;
        return $this;
    }

    // --- MÉTODOS SOBRESCRITOS PARA EXPONER EL FLAG A API PLATFORM ---
    #[Groups(['cotizacion:write'])]
    public function getSobreescribirTraduccion(): bool
    {
        return $this->sobreescribirTraduccion;
    }

    #[Groups(['cotizacion:write'])]
    public function setSobreescribirTraduccion(bool $sobreescribirTraduccion): self
    {
        $this->sobreescribirTraduccion = $sobreescribirTraduccion;
        return $this;
    }

    public function getCotservicio(): ?CotizacionCotservicio { return $this->cotservicio; }
    public function setCotservicio(?CotizacionCotservicio $cotservicio): self { $this->cotservicio = $cotservicio; return $this; }

    public function getDia(): int { return $this->dia; }
    public function setDia(int $dia): self { $this->dia = $dia; return $this; }

    public function getOrden(): int { return $this->orden; }
    public function setOrden(int $orden): self { $this->orden = $orden; return $this; }

    public function getFechaAbsoluta(): ?DateTimeImmutable { return $this->fechaAbsoluta; }
    public function setFechaAbsoluta(DateTimeImmutable $fechaAbsoluta): self { $this->fechaAbsoluta = $fechaAbsoluta; return $this; }

    public function getNombreSnapshot(): array { return $this->nombreSnapshot; }
    public function setNombreSnapshot(array $nombreSnapshot): self { $this->nombreSnapshot = $nombreSnapshot; return $this; }

    public function getContenidoSnapshot(): array { return $this->contenidoSnapshot; }
    public function setContenidoSnapshot(array $contenidoSnapshot): self { $this->contenidoSnapshot = $contenidoSnapshot; return $this; }

    public function getImagenesSnapshot(): array { return $this->imagenesSnapshot; }
    public function setImagenesSnapshot(array $imagenesSnapshot): self { $this->imagenesSnapshot = $imagenesSnapshot; return $this; }

    public function getCotcomponentes(): Collection { return $this->cotcomponentes; }
    public function addCotcomponente(CotizacionCotcomponente $cotcomponente): self
    {
        if (!$this->cotcomponentes->contains($cotcomponente)) {
            $this->cotcomponentes->add($cotcomponente);
            $cotcomponente->setCotsegmento($this);
        }
        return $this;
    }
    public function removeCotcomponente(CotizacionCotcomponente $cotcomponente): self
    {
        if ($this->cotcomponentes->removeElement($cotcomponente)) {
            if ($cotcomponente->getCotsegmento() === $this) { $cotcomponente->setCotsegmento(null); }
        }
        return $this;
    }
}