<?php

namespace App\Oweb\Entity;

use DateTime;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * CotizacionCotservicio
 */
#[ORM\Table(name: 'cot_cotservicio')]
#[ORM\Entity(repositoryClass: 'App\Oweb\Repository\CotizacionCotservicioRepository')]
class CotizacionCotservicio
{
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    #[ORM\Column(name: 'fechahorainicio', type: 'datetime')]
    private ?DateTimeInterface $fechahorainicio = null;

    #[ORM\Column(name: 'fechahorafin', type: 'datetime', nullable: true)]
    private ?DateTimeInterface $fechahorafin = null;

    #[ORM\ManyToOne(targetEntity: CotizacionCotizacion::class, inversedBy: 'cotservicios')]
    #[ORM\JoinColumn(name: 'cotizacion_id', referencedColumnName: 'id', nullable: false)]
    protected ?CotizacionCotizacion $cotizacion = null;

    #[ORM\ManyToOne(targetEntity: ServicioServicio::class)]
    #[ORM\JoinColumn(name: 'servicio_id', referencedColumnName: 'id', nullable: false)]
    protected ?ServicioServicio $servicio = null;

    #[ORM\ManyToOne(targetEntity: ServicioItinerario::class)]
    #[ORM\JoinColumn(name: 'itinerario_id', referencedColumnName: 'id', nullable: false)]
    protected ?ServicioItinerario $itinerario = null;

    #[ORM\OneToMany(
        mappedBy: 'cotservicio',
        targetEntity: CotizacionCotcomponente::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    #[ORM\OrderBy(['fechahorainicio' => 'ASC'])]
    private Collection $cotcomponentes;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $creado = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $modificado = null;

    public function __construct()
    {
        $this->cotcomponentes = new ArrayCollection();
    }

    /**
     * Ojo: se respeta tu lógica de clonado, incluido poner fechas en null
     * y clonar componentes manteniendo la relación hacia $this.
     */
    public function __clone()
    {
        if ($this->id) {
            $this->id = null;
            $this->setCreado(null);
            $this->setModificado(null);
            $newCotcomponentes = new ArrayCollection();
            foreach ($this->cotcomponentes as $cotcomponente) {
                $newCotcomponente = clone $cotcomponente;
                $newCotcomponente->setCotservicio($this);
                $newCotcomponentes->add($newCotcomponente);
            }
            $this->cotcomponentes = $newCotcomponentes;
        }
    }

    public function __toString(): string
    {
        if (empty($this->getServicio())) {
            return sprintf("Id: %s.", (string) $this->getId()) ?? '';
        }
        return (string) $this->getServicio()->getNombre();
    }

    /** @return int|null */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** @return string */
    public function getResumen(): string
    {
        return sprintf(
            '%s x%s: %s',
            $this->getCotizacion()->getFile()->getNombre(),
            $this->getCotizacion()->getNumeropasajeros(),
            $this->getServicio()->getNombre()
        );
    }

    public function setFechahorainicio(DateTimeInterface $fechahorainicio): self
    {
        $this->fechahorainicio = $fechahorainicio;
        return $this;
    }

    public function getFechahorainicio(): ?DateTimeInterface
    {
        return $this->fechahorainicio;
    }

    public function getFechainicio(): ?DateTimeInterface
    {
        return $this->fechahorainicio
            ? new DateTime($this->fechahorainicio->format('Y-m-d'))
            : null;
    }

    public function setFechahorafin(?DateTimeInterface $fechahorafin): self
    {
        $this->fechahorafin = $fechahorafin;
        return $this;
    }

    public function getFechahorafin(): ?DateTimeInterface
    {
        return $this->fechahorafin;
    }

    public function getFechafin(): ?DateTimeInterface
    {
        return $this->fechahorafin
            ? new DateTime($this->fechahorafin->format('Y-m-d'))
            : null;
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

    public function setCotizacion(?CotizacionCotizacion $cotizacion = null): self
    {
        $this->cotizacion = $cotizacion;
        return $this;
    }

    public function getCotizacion(): ?CotizacionCotizacion
    {
        return $this->cotizacion;
    }

    public function setServicio(?ServicioServicio $servicio = null): self
    {
        $this->servicio = $servicio;
        return $this;
    }

    public function getServicio(): ?ServicioServicio
    {
        return $this->servicio;
    }

    public function setItinerario(?ServicioItinerario $itinerario = null): self
    {
        $this->itinerario = $itinerario;
        return $this;
    }

    public function getItinerario(): ?ServicioItinerario
    {
        return $this->itinerario;
    }

    public function addCotcomponente(CotizacionCotcomponente $cotcomponente): self
    {
        $cotcomponente->setCotservicio($this);
        // Se respeta tu forma original de añadir a la colección
        $this->cotcomponentes[] = $cotcomponente;
        return $this;
    }

    public function removeCotcomponente(CotizacionCotcomponente $cotcomponente): void
    {
        $this->cotcomponentes->removeElement($cotcomponente);
    }

    /** @return Collection */
    public function getCotcomponentes(): Collection
    {
        return $this->cotcomponentes;
    }
}
