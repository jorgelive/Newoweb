<?php

namespace App\Entity;

use App\Entity\CotizacionCottarifa;
use App\Entity\CotizacionCotservicio;
use App\Entity\CotizacionEstadocotcomponente;
use App\Entity\ServicioComponente;
use App\Repository\CotizacionCotcomponenteRepository;
use DateTime;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Table(name: 'cot_cotcomponente')]
#[ORM\Entity(repositoryClass: CotizacionCotcomponenteRepository::class)]
class CotizacionCotcomponente
{
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CotizacionCotservicio::class, inversedBy: 'cotcomponentes')]
    #[ORM\JoinColumn(name: 'cotservicio_id', referencedColumnName: 'id', nullable: false)]
    protected ?CotizacionCotservicio $cotservicio = null;

    #[ORM\ManyToOne(targetEntity: ServicioComponente::class)]
    #[ORM\JoinColumn(name: 'componente_id', referencedColumnName: 'id', nullable: false)]
    protected ?ServicioComponente $componente = null;

    #[ORM\ManyToOne(targetEntity: CotizacionEstadocotcomponente::class)]
    #[ORM\JoinColumn(name: 'estadocotcomponente_id', referencedColumnName: 'id', nullable: false)]
    protected ?CotizacionEstadocotcomponente $estadocotcomponente = null;

    /** @var Collection<int, CotizacionCottarifa> */
    #[ORM\OneToMany(mappedBy: 'cotcomponente', targetEntity: CotizacionCottarifa::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $cottarifas;

    #[ORM\Column(name: 'cantidad', type: 'integer', options: ['default' => 1])]
    private int $cantidad = 1;

    #[ORM\Column(name: 'fechahorainicio', type: 'datetime')]
    private DateTimeInterface $fechahorainicio;

    #[ORM\Column(name: 'fechahorafin', type: 'datetime')]
    private ?DateTimeInterface $fechahorafin = null;

    // Timestampable NO NULL (consigna)
    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?DateTimeInterface $creado = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?DateTimeInterface $modificado = null;

    public function __construct()
    {
        $this->cottarifas = new ArrayCollection();
    }

    public function __clone(): void
    {
        if ($this->id) {
            $this->id = null;
            $this->setCreado(null);
            $this->setModificado(null);

            $newCottarifas = new ArrayCollection();
            foreach ($this->cottarifas as $cottarifa) {
                $clone = clone $cottarifa;
                $clone->setCotcomponente($this);
                $newCottarifas->add($clone);
            }
            $this->cottarifas = $newCottarifas;
        }
    }

    public function __toString(): string
    {
        if (empty($this->getComponente())) {
            return sprintf('id: %s', $this->getId() ?? '');
        }
        $nombre = $this->getComponente()->getNombre();
        return $this->getCantidad() > 1 ? sprintf('%s x%s', $nombre, $this->getCantidad()) : $nombre;
    }

    public function getId(): ?int { return $this->id; }

    public function getNombre()
    {
        return $this->getComponente()->getNombre();
    }

    public function setCreado(?DateTimeInterface $creado): self { $this->creado = $creado; return $this; }
    public function getCreado(): ?DateTimeInterface { return $this->creado; }

    public function setModificado(?DateTimeInterface $modificado): self { $this->modificado = $modificado; return $this; }
    public function getModificado(): ?DateTimeInterface { return $this->modificado; }

    public function setEstadocotcomponente(CotizacionEstadocotcomponente $estadocotcomponente): self
    { $this->estadocotcomponente = $estadocotcomponente; return $this; }

    public function getEstadocotcomponente(): ?CotizacionEstadocotcomponente
    { return $this->estadocotcomponente; }

    public function setCotservicio(?CotizacionCotservicio $cotservicio = null): self
    { $this->cotservicio = $cotservicio; return $this; }

    public function getCotservicio(): ?CotizacionCotservicio
    { return $this->cotservicio; }

    public function setComponente(?ServicioComponente $componente = null): self
    { $this->componente = $componente; return $this; }

    public function getComponente(): ?ServicioComponente
    { return $this->componente; }

    public function addCottarifa(CotizacionCottarifa $cottarifa): self
    {
        $cottarifa->setCotcomponente($this);
        $this->cottarifas->add($cottarifa);
        return $this;
    }

    public function removeCottarifa(CotizacionCottarifa $cottarifa): void
    {
        $this->cottarifas->removeElement($cottarifa);
    }

    /** @return Collection<int, CotizacionCottarifa> */
    public function getCottarifas(): Collection
    { return $this->cottarifas; }

    public function setCantidad(int $cantidad): self { $this->cantidad = $cantidad; return $this; }
    public function getCantidad(): int { return $this->cantidad; }

    public function setFechahorainicio(DateTimeInterface $fechahorainicio): self
    { $this->fechahorainicio = $fechahorainicio; return $this; }

    public function getFechahorainicio(): DateTimeInterface
    { return $this->fechahorainicio; }

    public function getFechainicio(): ?DateTimeInterface
    { return new DateTime($this->fechahorainicio->format('Y-m-d')); }

    public function setFechahorafin(?DateTimeInterface $fechahorafin = null): self
    { $this->fechahorafin = $fechahorafin; return $this; }

    public function getFechahorafin(): ?DateTimeInterface
    { return $this->fechahorafin; }

    public function getFechafin(): ?DateTimeInterface
    { return $this->fechahorafin ? new DateTime($this->fechahorafin->format('Y-m-d')) : null; }
}
