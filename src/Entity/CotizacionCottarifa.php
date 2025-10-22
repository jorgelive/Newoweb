<?php

namespace App\Entity;

use DateTimeInterface;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * CotizacionCottarifa
 */
#[ORM\Table(name: 'cot_cottarifa')]
#[ORM\Entity]
class CotizacionCottarifa
{
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    #[ORM\Column(name: 'cantidad', type: 'integer')]
    private ?int $cantidad = null;

    #[ORM\ManyToOne(targetEntity: 'CotizacionCotcomponente', inversedBy: 'cottarifas')]
    #[ORM\JoinColumn(name: 'cotcomponente_id', referencedColumnName: 'id', nullable: false)]
    protected ?CotizacionCotcomponente $cotcomponente = null;

    #[ORM\ManyToOne(targetEntity: 'ServicioProvider', inversedBy: 'cottarifas')]
    #[ORM\JoinColumn(name: 'provider_id', referencedColumnName: 'id', nullable: true)]
    protected ?ServicioProvider $provider = null;

    #[ORM\ManyToOne(targetEntity: 'ServicioTarifa')]
    #[ORM\JoinColumn(name: 'tarifa_id', referencedColumnName: 'id', nullable: false)]
    protected ?ServicioTarifa $tarifa = null;

    #[ORM\ManyToOne(targetEntity: 'MaestroMoneda')]
    #[ORM\JoinColumn(name: 'moneda_id', referencedColumnName: 'id', nullable: false)]
    protected ?MaestroMoneda $moneda = null;

    /**
     * decimal(7,2) → manejar como string para evitar floats.
     */
    #[ORM\Column(name: 'monto', type: 'decimal', precision: 7, scale: 2, nullable: false)]
    private ?string $monto = null;

    #[ORM\ManyToOne(targetEntity: 'ServicioTipotarifa')]
    #[ORM\JoinColumn(name: 'tipotarifa_id', referencedColumnName: 'id', nullable: false)]
    protected ?ServicioTipotarifa $tipotarifa = null;

    #[ORM\OneToMany(
        mappedBy: 'cottarifa',
        targetEntity: 'CotizacionCottarifadetalle',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    #[ORM\OrderBy(['tipotarifadetalle' => 'ASC'])]
    private Collection $cottarifadetalles;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $creado = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $modificado = null;

    public function __construct()
    {
        $this->cottarifadetalles = new ArrayCollection();
    }

    public function __toString(): string
    {
        if (empty($this->getTarifa())) {
            return sprintf("Id: %s.", (string) $this->getId()) ?? '';
        }
        return (string) $this->getTarifa()->getNombre();
    }

    /**
     * Se mantiene tu lógica de clonado: resetear id/fechas y clonar detalles.
     */
    public function __clone()
    {
        if ($this->id) {
            $this->id = null;
            $this->setCreado(null);
            $this->setModificado(null);
            $newCottarifadetalles = new ArrayCollection();
            foreach ($this->cottarifadetalles as $cottarifadetalle) {
                $newCottarifadetalle = clone $cottarifadetalle;
                $newCottarifadetalle->setCottarifa($this); // respeta tu setter existente
                $newCottarifadetalles->add($newCottarifadetalle);
            }
            $this->cottarifadetalles = $newCottarifadetalles;
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setCantidad(int $cantidad): self
    {
        $this->cantidad = $cantidad;
        return $this;
    }

    public function getCantidad(): ?int
    {
        return $this->cantidad;
    }

    public function setMonto(string $monto): self
    {
        $this->monto = $monto;
        return $this;
    }

    public function getMonto(): ?string
    {
        return $this->monto;
    }

    public function setCreado(?DateTimeInterface $creado): self
    {
        $this->creado = $creado;
        return $this;
    }

    public function getCreado(): ?DateTimeInterface
    {
        return $this->creado;
    }

    public function setModificado(?DateTimeInterface $modificado): self
    {
        $this->modificado = $modificado;
        return $this;
    }

    public function getModificado(): ?DateTimeInterface
    {
        return $this->modificado;
    }

    public function setCotcomponente(?CotizacionCotcomponente $cotcomponente = null): self
    {
        $this->cotcomponente = $cotcomponente;
        return $this;
    }

    public function getCotcomponente(): ?CotizacionCotcomponente
    {
        return $this->cotcomponente;
    }

    public function setProvider(?ServicioProvider $provider = null): self
    {
        $this->provider = $provider;
        return $this;
    }

    public function getProvider(): ?ServicioProvider
    {
        return $this->provider;
    }

    public function setTarifa(?ServicioTarifa $tarifa = null): self
    {
        $this->tarifa = $tarifa;
        return $this;
    }

    public function getTarifa(): ?ServicioTarifa
    {
        return $this->tarifa;
    }

    public function setMoneda(?MaestroMoneda $moneda = null): self
    {
        $this->moneda = $moneda;
        return $this;
    }

    public function getMoneda(): ?MaestroMoneda
    {
        return $this->moneda;
    }

    public function setTipotarifa(?ServicioTipotarifa $tipotarifa = null): self
    {
        $this->tipotarifa = $tipotarifa;
        return $this;
    }

    public function getTipotarifa(): ?ServicioTipotarifa
    {
        return $this->tipotarifa;
    }

    public function addCottarifadetalle(CotizacionCottarifadetalle $cottarifadetalle): self
    {
        $cottarifadetalle->setCottarifa($this); // mantener la sincronización como lo tienes
        $this->cottarifadetalles[] = $cottarifadetalle;
        return $this;
    }

    public function removeCottarifadetalle(CotizacionCottarifadetalle $cottarifadetalle): void
    {
        $this->cottarifadetalles->removeElement($cottarifadetalle);
    }

    public function getCottarifadetalles(): Collection
    {
        return $this->cottarifadetalles;
    }
}
