<?php

declare(strict_types=1);

namespace App\Cotizacion\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Logística inmutable. Congela los ítems bilingües, su estado en un JSON y su costo.
 * Representa la orden de servicio operativa para la cotización.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cotizacion_cotcomponente')]
class CotizacionCotcomponente
{
    use IdTrait;
    use TimestampTrait;

    #[ORM\ManyToOne(targetEntity: CotizacionCotservicio::class, inversedBy: 'cotcomponentes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?CotizacionCotservicio $cotservicio = null;

    /**
     * Vincula este costo logístico con el párrafo narrativo que lo vendió al cliente.
     * Puede ser nulo si es un costo puramente operativo (ej. fee bancario) que no aparece en el storytelling.
     */
    #[ORM\ManyToOne(targetEntity: CotizacionSegmento::class, inversedBy: 'cotcomponentes')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CotizacionSegmento $cotsegmento = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?DateTimeImmutable $fechaEjecucion = null;

    /**
     * JSON Mutante que guarda los ítems:
     * [{"diccionarioId": "UUID", "titulos": {"es":"...", "en":"..."}, "modoBase":"no_incluido", "modoActual":"incluido", "tarifaCotizacionGeneradaId":"UUID"}]
     */
    #[ORM\Column(type: 'json')]
    private array $snapshotItems = [];

    #[ORM\OneToMany(mappedBy: 'cotcomponente', targetEntity: CotizacionCottarifa::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $cottarifas;

    public function __construct()
    {
        $this->initializeId();
        $this->cottarifas = new ArrayCollection();
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

    public function getCotsegmento(): ?CotizacionSegmento
    {
        return $this->cotsegmento;
    }

    public function setCotsegmento(?CotizacionSegmento $cotsegmento): self
    {
        $this->cotsegmento = $cotsegmento;
        return $this;
    }

    public function getFechaEjecucion(): ?DateTimeImmutable
    {
        return $this->fechaEjecucion;
    }

    public function setFechaEjecucion(DateTimeImmutable $fechaEjecucion): self
    {
        $this->fechaEjecucion = $fechaEjecucion;
        return $this;
    }

    public function getSnapshotItems(): array
    {
        return $this->snapshotItems;
    }

    public function setSnapshotItems(array $snapshotItems): self
    {
        $this->snapshotItems = $snapshotItems;
        return $this;
    }

    /**
     * Devuelve las tarifas aplicadas a este componente congelado.
     *
     * @return Collection<int, CotizacionCottarifa>
     */
    public function getCottarifas(): Collection
    {
        return $this->cottarifas;
    }

    public function addCottarifa(CotizacionCottarifa $cottarifa): self
    {
        if (!$this->cottarifas->contains($cottarifa)) {
            $this->cottarifas->add($cottarifa);
            $cottarifa->setCotcomponente($this);
        }
        return $this;
    }

    public function removeCottarifa(CotizacionCottarifa $cottarifa): self
    {
        if ($this->cottarifas->removeElement($cottarifa)) {
            if ($cottarifa->getCotcomponente() === $this) {
                $cottarifa->setCotcomponente(null);
            }
        }
        return $this;
    }
}