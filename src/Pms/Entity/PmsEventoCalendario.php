<?php

namespace App\Pms\Entity;

use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity]
#[ORM\Table(name: 'pms_evento_calendario')]
class PmsEventoCalendario
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PmsUnidad::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?PmsUnidad $pmsUnidad = null;


    #[ORM\ManyToOne(targetEntity: PmsReserva::class, inversedBy: 'eventosCalendario')]
    #[ORM\JoinColumn(nullable: true)]
    private ?PmsReserva $reserva = null;

    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $inicio = null;

    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $fin = null;

    // --- CAMPOS BEDS24 V2 ---


    #[ORM\OneToMany(
        mappedBy: 'evento',
        targetEntity: PmsEventoBeds24Link::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $beds24Links;

    /**
     * Status crudo de Beds24 para esta habitación (solo lectura en el PMS).
     */
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $estadoBeds24 = null;

    /**
     * Sub-status crudo de Beds24 (ej: "new") para esta habitación (solo lectura en el PMS).
     */
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $subestadoBeds24 = null;

    #[ORM\ManyToOne(targetEntity: PmsEventoEstado::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?PmsEventoEstado $estado = null;

    #[ORM\ManyToOne(targetEntity: PmsEventoEstadoPago::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?PmsEventoEstadoPago $estadoPago = null;

    /**
     * Cantidad de adultos para esta unidad (Beds24 room-level).
     */
    #[ORM\Column(type: 'integer', nullable: false, options: ['default' => 0])]
    private int $cantidadAdultos = 0;

    /**
     * Cantidad de niños para esta unidad (Beds24 room-level).
     */
    #[ORM\Column(type: 'integer', nullable: false, options: ['default' => 0])]
    private int $cantidadNinos = 0;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    private ?string $monto = '0.00';

    /**
     * Comisión cobrada por el canal (Beds24 / OTA) para esta unidad.
     */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $comision = null;

    /**
     * Descripción completa de la tarifa (Beds24 rateDescription).
     * Se guarda tal cual para auditoría y depuración.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $rateDescription = null;

    // ------------------------

    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    private ?string $tituloCache = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $origenCache = null;


    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $created = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $updated = null;

    public function __construct()
    {
        $this->beds24Links = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getPmsUnidad(): ?PmsUnidad { return $this->pmsUnidad; }
    public function setPmsUnidad(?PmsUnidad $pmsUnidad): self { $this->pmsUnidad = $pmsUnidad; return $this; }

    public function getReserva(): ?PmsReserva { return $this->reserva; }
    public function setReserva(?PmsReserva $reserva): self { $this->reserva = $reserva; return $this; }

    public function getInicio(): ?DateTimeInterface { return $this->inicio; }
    public function setInicio(?DateTimeInterface $inicio): self { $this->inicio = $inicio; return $this; }

    public function getFin(): ?DateTimeInterface { return $this->fin; }
    public function setFin(?DateTimeInterface $fin): self { $this->fin = $fin; return $this; }

    // --- Getters B24 ---
    public function getBeds24Links(): Collection
    {
        return $this->beds24Links;
    }

    public function addBeds24Link(PmsEventoBeds24Link $link): self
    {
        if (!$this->beds24Links->contains($link)) {
            $this->beds24Links->add($link);
            $link->setEvento($this);
        }

        return $this;
    }

    public function removeBeds24Link(PmsEventoBeds24Link $link): self
    {
        if ($this->beds24Links->removeElement($link)) {
            if ($link->getEvento() === $this) {
                $link->setEvento(null);
            }
        }

        return $this;
    }

    public function getEstadoBeds24(): ?string
    {
        return $this->estadoBeds24;
    }

    public function setEstadoBeds24(?string $estadoBeds24): self
    {
        $this->estadoBeds24 = $estadoBeds24;
        return $this;
    }

    public function getSubestadoBeds24(): ?string
    {
        return $this->subestadoBeds24;
    }

    public function setSubestadoBeds24(?string $subestadoBeds24): self
    {
        $this->subestadoBeds24 = $subestadoBeds24;
        return $this;
    }

    public function getEstado(): ?PmsEventoEstado { return $this->estado; }
    public function setEstado(?PmsEventoEstado $estado): self { $this->estado = $estado; return $this; }

    public function getEstadoPago(): ?PmsEventoEstadoPago
    {
        return $this->estadoPago;
    }

    public function setEstadoPago(?PmsEventoEstadoPago $estadoPago): self
    {
        $this->estadoPago = $estadoPago;
        return $this;
    }

    public function getCantidadAdultos(): int
    {
        return $this->cantidadAdultos;
    }

    public function setCantidadAdultos(int $cantidadAdultos): self
    {
        $this->cantidadAdultos = $cantidadAdultos;
        return $this;
    }

    public function getCantidadNinos(): int
    {
        return $this->cantidadNinos;
    }

    public function setCantidadNinos(int $cantidadNinos): self
    {
        $this->cantidadNinos = $cantidadNinos;
        return $this;
    }


    public function getMonto(): ?string { return $this->monto; }
    public function setMonto(?string $monto): self { $this->monto = $monto; return $this; }

    public function getComision(): ?string
    {
        return $this->comision;
    }

    public function setComision(?string $comision): self
    {
        $this->comision = $comision;
        return $this;
    }

    public function getRateDescription(): ?string
    {
        return $this->rateDescription;
    }

    public function setRateDescription(?string $rateDescription): self
    {
        $this->rateDescription = $rateDescription;
        return $this;
    }

    // -------------------

    public function getTituloCache(): ?string { return $this->tituloCache; }
    public function setTituloCache(?string $tituloCache): self { $this->tituloCache = $tituloCache; return $this; }

    public function getOrigenCache(): ?string { return $this->origenCache; }
    public function setOrigenCache(?string $origenCache): self { $this->origenCache = $origenCache; return $this; }


    public function getCreated(): ?DateTimeInterface { return $this->created; }
    public function getUpdated(): ?DateTimeInterface { return $this->updated; }

    public function __toString(): string
    {
        if ($this->tituloCache) return $this->tituloCache;
        return $this->pmsUnidad?->getNombre() ?? 'Unidad';
    }
}
