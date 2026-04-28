<?php

declare(strict_types=1);

namespace App\Cotizacion\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * La propuesta comercial específica (Versión) entregada al cliente.
 * Actúa como la Caja Fuerte que agrupa servicios, logística y notas inmutables.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cotizacion_cotizacion')]
class Cotizacion
{
    use IdTrait;
    use TimestampTrait;

    #[ORM\ManyToOne(targetEntity: CotizacionFile::class, inversedBy: 'cotizaciones')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?CotizacionFile $file = null;

    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $fechaExpiracion = null;

    #[ORM\Column(type: 'string', length: 10, options: ['default' => 'USD'])]
    private string $monedaGlobal = 'USD';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, options: ['default' => '0.00'])]
    private string $totalCosto = '0.00';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, options: ['default' => '0.00'])]
    private string $totalVenta = '0.00';

    #[ORM\OneToMany(mappedBy: 'cotizacion', targetEntity: CotizacionCotservicio::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['fechaInicioAbsoluta' => 'ASC'])]
    private Collection $cotservicios;

    /**
     * Snapshots inmutables de las notas, historias y políticas asociadas a esta versión.
     */
    #[ORM\OneToMany(mappedBy: 'cotizacion', targetEntity: CotizacionNota::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $cotnotas;

    public function __construct()
    {
        $this->initializeId();
        $this->cotservicios = new ArrayCollection();
        $this->cotnotas = new ArrayCollection();
    }

    public function __toString(): string
    {
        return sprintf('V%d - %s', $this->version, $this->file ? $this->file->getNombreGrupo() : 'Sin File');
    }

    public function getFile(): ?CotizacionFile
    {
        return $this->file;
    }

    public function setFile(?CotizacionFile $file): self
    {
        $this->file = $file;
        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function setVersion(int $version): self
    {
        $this->version = $version;
        return $this;
    }

    public function getFechaExpiracion(): ?\DateTimeImmutable
    {
        return $this->fechaExpiracion;
    }

    public function setFechaExpiracion(?\DateTimeImmutable $fechaExpiracion): self
    {
        $this->fechaExpiracion = $fechaExpiracion;
        return $this;
    }

    public function getMonedaGlobal(): string
    {
        return $this->monedaGlobal;
    }

    public function setMonedaGlobal(string $monedaGlobal): self
    {
        $this->monedaGlobal = $monedaGlobal;
        return $this;
    }

    public function getTotalCosto(): string
    {
        return $this->totalCosto;
    }

    public function setTotalCosto(string $totalCosto): self
    {
        $this->totalCosto = $totalCosto;
        return $this;
    }

    public function getTotalVenta(): string
    {
        return $this->totalVenta;
    }

    public function setTotalVenta(string $totalVenta): self
    {
        $this->totalVenta = $totalVenta;
        return $this;
    }

    /**
     * @return Collection<int, CotizacionCotservicio>
     */
    public function getCotservicios(): Collection
    {
        return $this->cotservicios;
    }

    public function addCotservicio(CotizacionCotservicio $cotservicio): self
    {
        if (!$this->cotservicios->contains($cotservicio)) {
            $this->cotservicios->add($cotservicio);
            $cotservicio->setCotizacion($this);
        }
        return $this;
    }

    public function removeCotservicio(CotizacionCotservicio $cotservicio): self
    {
        if ($this->cotservicios->removeElement($cotservicio)) {
            if ($cotservicio->getCotizacion() === $this) {
                $cotservicio->setCotizacion(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, CotizacionNota>
     */
    public function getCotnotas(): Collection
    {
        return $this->cotnotas;
    }

    public function addCotnota(CotizacionNota $cotnota): self
    {
        if (!$this->cotnotas->contains($cotnota)) {
            $this->cotnotas->add($cotnota);
            $cotnota->setCotizacion($this);
        }
        return $this;
    }

    public function removeCotnota(CotizacionNota $cotnota): self
    {
        if ($this->cotnotas->removeElement($cotnota)) {
            if ($cotnota->getCotizacion() === $this) {
                $cotnota->setCotizacion(null);
            }
        }
        return $this;
    }
}