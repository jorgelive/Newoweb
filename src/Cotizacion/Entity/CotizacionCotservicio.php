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

/**
 * Cabecera del servicio clonado. Actúa como hito diario.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cotizacion_cotservicio')]
class CotizacionCotservicio
{
    use IdTrait;
    use TimestampTrait;

    #[ORM\ManyToOne(targetEntity: Cotizacion::class, inversedBy: 'cotservicios')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Cotizacion $cotizacion = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'json')]
    private array $nombreSnapshot = [];

    // 🔥 NUEVO: Para saber qué plantilla se eligió en el dropdown "Itinerario"
    #[Groups(['cotizacion:item:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $itinerarioNombreSnapshot = null;

    // 🔥 MODIFICADO: Solo Fecha, sin Hora. Es un milestone.
    #[Groups(['cotizacion:item:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $fechaInicioAbsoluta = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write'])]
    #[ORM\OneToMany(mappedBy: 'cotservicio', targetEntity: CotizacionCotcomponente::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['fechaHoraInicio' => 'ASC'])]
    private Collection $cotcomponentes;

    #[Groups(['cotizacion:item:read', 'cotizacion:write'])]
    #[ORM\OneToMany(mappedBy: 'cotservicio', targetEntity: CotizacionSegmento::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['dia' => 'ASC', 'orden' => 'ASC'])]
    private Collection $cotsegmentos;

    public function __construct()
    {
        $this->initializeId();
        $this->cotcomponentes = new ArrayCollection();
        $this->cotsegmentos = new ArrayCollection();
    }

    // --- GETTERS Y SETTERS EXPLÍCITOS ---

    public function getCotizacion(): ?Cotizacion
    {
        return $this->cotizacion;
    }

    public function setCotizacion(?Cotizacion $cotizacion): self
    {
        $this->cotizacion = $cotizacion;
        return $this;
    }

    public function getNombreSnapshot(): array
    {
        return $this->nombreSnapshot;
    }

    public function setNombreSnapshot(array $nombreSnapshot): self
    {
        $this->nombreSnapshot = $nombreSnapshot;
        return $this;
    }

    public function getItinerarioNombreSnapshot(): ?string
    {
        return $this->itinerarioNombreSnapshot;
    }

    public function setItinerarioNombreSnapshot(?string $itinerarioNombreSnapshot): self
    {
        $this->itinerarioNombreSnapshot = $itinerarioNombreSnapshot;
        return $this;
    }

    public function getFechaInicioAbsoluta(): ?DateTimeImmutable
    {
        return $this->fechaInicioAbsoluta;
    }

    public function setFechaInicioAbsoluta(?DateTimeImmutable $fechaInicioAbsoluta): self
    {
        $this->fechaInicioAbsoluta = $fechaInicioAbsoluta;
        return $this;
    }

    public function getCotcomponentes(): Collection
    {
        return $this->cotcomponentes;
    }

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
            if ($cotcomponente->getCotservicio() === $this) {
                $cotcomponente->setCotservicio(null);
            }
        }
        return $this;
    }

    public function getCotsegmentos(): Collection
    {
        return $this->cotsegmentos;
    }

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
            if ($cotsegmento->getCotservicio() === $this) {
                $cotsegmento->setCotservicio(null);
            }
        }
        return $this;
    }
}