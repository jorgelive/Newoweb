<?php

namespace App\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * CotizacionCottarifadetalle
 */
#[ORM\Table(name: 'cot_cottarifadetalle')]
#[ORM\Entity]
class CotizacionCottarifadetalle
{
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: 'CotizacionCottarifa', inversedBy: 'cottarifadetalles')]
    #[ORM\JoinColumn(name: 'cottarifa_id', referencedColumnName: 'id', nullable: false)]
    protected ?CotizacionCottarifa $cottarifa = null;

    #[ORM\Column(name: 'detalle', type: 'string', length: 255)]
    private ?string $detalle = null; // Inicializado a null por compatibilidad con Symfony

    #[ORM\ManyToOne(targetEntity: 'ServicioTipotarifadetalle')]
    #[ORM\JoinColumn(name: 'tipotarifadetalle_id', referencedColumnName: 'id', nullable: false)]
    protected ?ServicioTipotarifadetalle $tipotarifadetalle = null;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $creado = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $modificado = null;

    public function __toString(): string
    {
        return ($this->getTipotarifadetalle() ? $this->getTipotarifadetalle() : '') . '-' . ($this->getDetalle() ?? '');
    }

    public function __clone(): void
    {
        if ($this->id) {
            $this->id = null;
            $this->setCreado(null);
            $this->setModificado(null);
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setDetalle(?string $detalle): self
    {
        $this->detalle = $detalle;
        return $this;
    }

    public function getDetalle(): ?string
    {
        return $this->detalle;
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

    public function setCottarifa(?CotizacionCottarifa $cottarifa = null): self
    {
        $this->cottarifa = $cottarifa;
        return $this;
    }

    public function getCottarifa(): ?CotizacionCottarifa
    {
        return $this->cottarifa;
    }

    public function setTipotarifadetalle(ServicioTipotarifadetalle $tipotarifadetalle): self
    {
        $this->tipotarifadetalle = $tipotarifadetalle;
        return $this;
    }

    public function getTipotarifadetalle(): ?ServicioTipotarifadetalle
    {
        return $this->tipotarifadetalle;
    }
}
