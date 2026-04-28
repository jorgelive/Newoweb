<?php

declare(strict_types=1);

namespace App\Cotizacion\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * El Expediente raíz. Agrupa todas las propuestas comerciales de un cliente o grupo.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cotizacion_file')]
class CotizacionFile
{
    use IdTrait;
    use TimestampTrait;

    #[ORM\Column(type: 'string', length: 50, unique: true)]
    private ?string $correlativo = null;

    #[ORM\Column(type: 'string', length: 150)]
    private ?string $nombreGrupo = null;

    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $pasajeroPrincipal = null;

    #[ORM\Column(type: 'string', length: 30, options: ['default' => 'abierto'])]
    private string $estado = 'abierto';

    #[ORM\OneToMany(mappedBy: 'file', targetEntity: Cotizacion::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['version' => 'DESC'])]
    private Collection $cotizaciones;

    public function __construct()
    {
        $this->initializeId();
        $this->cotizaciones = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->nombreGrupo ?? 'File sin nombre';
    }

    public function getCorrelativo(): ?string
    {
        return $this->correlativo;
    }

    public function setCorrelativo(string $correlativo): self
    {
        $this->correlativo = $correlativo;
        return $this;
    }

    public function getNombreGrupo(): ?string
    {
        return $this->nombreGrupo;
    }

    public function setNombreGrupo(string $nombreGrupo): self
    {
        $this->nombreGrupo = $nombreGrupo;
        return $this;
    }

    public function getPasajeroPrincipal(): ?string
    {
        return $this->pasajeroPrincipal;
    }

    public function setPasajeroPrincipal(?string $pasajeroPrincipal): self
    {
        $this->pasajeroPrincipal = $pasajeroPrincipal;
        return $this;
    }

    public function getEstado(): string
    {
        return $this->estado;
    }

    public function setEstado(string $estado): self
    {
        $this->estado = $estado;
        return $this;
    }

    public function getCotizaciones(): Collection
    {
        return $this->cotizaciones;
    }

    public function addCotizacion(Cotizacion $cotizacion): self
    {
        if (!$this->cotizaciones->contains($cotizacion)) {
            $this->cotizaciones->add($cotizacion);
            $cotizacion->setFile($this);
        }
        return $this;
    }

    public function removeCotizacion(Cotizacion $cotizacion): self
    {
        if ($this->cotizaciones->removeElement($cotizacion)) {
            if ($cotizacion->getFile() === $this) {
                $cotizacion->setFile(null);
            }
        }
        return $this;
    }


}
