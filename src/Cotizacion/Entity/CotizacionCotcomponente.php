<?php

declare(strict_types=1);

namespace App\Cotizacion\Entity;

use App\Cotizacion\Enum\ComponenteEstadoEnum;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Travel\Enum\ItemModoEnum;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * Logística inmutable. Congela los ítems bilingües, su estado y horarios precisos.
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

    #[ORM\ManyToOne(targetEntity: CotizacionSegmento::class, inversedBy: 'cotcomponentes')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CotizacionSegmento $cotsegmento = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $nombreSnapshot = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $cantidad = 1;

    // 🔥 MODIFICADO: Ahora utiliza el nuevo Enum mapeando a tu antigua tabla legacy
    #[Groups(['cotizacion:item:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'string', length: 30, enumType: ComponenteEstadoEnum::class, options: ['default' => 'Pendiente'])]
    private ComponenteEstadoEnum $estado = ComponenteEstadoEnum::PENDIENTE;

    #[Groups(['cotizacion:item:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'string', length: 30, enumType: ItemModoEnum::class, options: ['default' => 'incluido'])]
    private ItemModoEnum $modo = ItemModoEnum::INCLUIDO;

    #[Groups(['cotizacion:item:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $fechaHoraInicio = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $fechaHoraFin = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'json')]
    private array $snapshotItems = [];

    #[Groups(['cotizacion:item:read', 'cotizacion:write'])]
    #[ORM\OneToMany(mappedBy: 'cotcomponente', targetEntity: CotizacionCottarifa::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $cottarifas;

    public function __construct()
    {
        $this->initializeId();
        $this->cottarifas = new ArrayCollection();
    }

    // --- GETTERS Y SETTERS EXPLÍCITOS ---

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

    public function getNombreSnapshot(): ?string
    {
        return $this->nombreSnapshot;
    }

    public function setNombreSnapshot(?string $nombreSnapshot): self
    {
        $this->nombreSnapshot = $nombreSnapshot;
        return $this;
    }

    public function getCantidad(): int
    {
        return $this->cantidad;
    }

    public function setCantidad(int $cantidad): self
    {
        $this->cantidad = $cantidad;
        return $this;
    }

    public function getEstado(): ComponenteEstadoEnum
    {
        return $this->estado;
    }

    public function setEstado(ComponenteEstadoEnum $estado): self
    {
        $this->estado = $estado;
        return $this;
    }

    public function getModo(): ItemModoEnum
    {
        return $this->modo;
    }

    public function setModo(ItemModoEnum $modo): self
    {
        $this->modo = $modo;
        return $this;
    }

    public function getFechaHoraInicio(): ?DateTimeImmutable
    {
        return $this->fechaHoraInicio;
    }

    public function setFechaHoraInicio(?DateTimeImmutable $fechaHoraInicio): self
    {
        $this->fechaHoraInicio = $fechaHoraInicio;
        return $this;
    }

    public function getFechaHoraFin(): ?DateTimeImmutable
    {
        return $this->fechaHoraFin;
    }

    public function setFechaHoraFin(?DateTimeImmutable $fechaHoraFin): self
    {
        $this->fechaHoraFin = $fechaHoraFin;
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