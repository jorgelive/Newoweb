<?php

declare(strict_types=1);

namespace App\Cotizacion\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use DateTimeImmutable;

/**
 * Storytelling inmutable con coordenadas espaciotemporales (No Sabanoso).
 * Es la copia fiel del TravelSegmento para una cotización específica.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cotizacion_segmento')]
class CotizacionSegmento
{
    use IdTrait;
    use TimestampTrait;

    #[ORM\ManyToOne(targetEntity: CotizacionCotservicio::class, inversedBy: 'cotsegmentos')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?CotizacionCotservicio $cotservicio = null;

    #[ORM\Column(type: 'integer')]
    private int $dia = 1;

    #[ORM\Column(type: 'integer')]
    private int $orden = 1;

    #[ORM\Column(type: 'date_immutable')]
    private ?DateTimeImmutable $fechaAbsoluta = null;

    #[ORM\Column(type: 'json')]
    private array $contenidoSnapshot = [];

    #[ORM\Column(type: 'json')]
    private array $imagenesSnapshot = [];

    /**
     * Logística operativa amarrada a este bloque narrativo.
     */
    #[ORM\OneToMany(mappedBy: 'cotsegmento', targetEntity: CotizacionCotcomponente::class)]
    private Collection $cotcomponentes;

    public function __construct()
    {
        $this->initializeId();
        $this->cotcomponentes = new ArrayCollection();
    }

    public function getCotservicio(): ?CotizacionCotservicio
    {
        return $this->cotservicio;
    }

    public function setCotservicio(?CotizacionCotservicio $cotservicio): self
    {
        $this->cotservicio = $cotservicio;
        return $this;
    }

    public function getDia(): int
    {
        return $this->dia;
    }

    public function setDia(int $dia): self
    {
        $this->dia = $dia;
        return $this;
    }

    public function getOrden(): int
    {
        return $this->orden;
    }

    public function setOrden(int $orden): self
    {
        $this->orden = $orden;
        return $this;
    }

    public function getFechaAbsoluta(): ?DateTimeImmutable
    {
        return $this->fechaAbsoluta;
    }

    public function setFechaAbsoluta(DateTimeImmutable $fechaAbsoluta): self
    {
        $this->fechaAbsoluta = $fechaAbsoluta;
        return $this;
    }

    public function getContenidoSnapshot(): array
    {
        return $this->contenidoSnapshot;
    }

    public function setContenidoSnapshot(array $contenidoSnapshot): self
    {
        $this->contenidoSnapshot = $contenidoSnapshot;
        return $this;
    }

    public function getImagenesSnapshot(): array
    {
        return $this->imagenesSnapshot;
    }

    public function setImagenesSnapshot(array $imagenesSnapshot): self
    {
        $this->imagenesSnapshot = $imagenesSnapshot;
        return $this;
    }

    /**
     * @return Collection<int, CotizacionCotcomponente>
     */
    public function getCotcomponentes(): Collection
    {
        return $this->cotcomponentes;
    }

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
            if ($cotcomponente->getCotsegmento() === $this) {
                $cotcomponente->setCotsegmento(null);
            }
        }
        return $this;
    }
}