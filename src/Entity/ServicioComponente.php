<?php
declare(strict_types=1);

namespace App\Entity;

use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Table(name: 'ser_componente')]
#[ORM\Entity]
class ServicioComponente
{
    #[ORM\Column(type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 100)]
    private ?string $nombre = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $anticipacionalerta = null;

    /** Colección ordenada por 'titulo' ASC (lado 1:N con items). */
    #[ORM\OneToMany(
        mappedBy: 'componente',
        targetEntity: ServicioComponenteitem::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    #[ORM\OrderBy(['titulo' => 'ASC'])]
    private Collection $componenteitems;

    /** Lado inverso de la relación M:N con ServicioServicio::componentes. */
    #[ORM\ManyToMany(targetEntity: ServicioServicio::class, mappedBy: 'componentes')]
    protected Collection $servicios;

    #[ORM\ManyToOne(targetEntity: ServicioTipocomponente::class)]
    #[ORM\JoinColumn(name: 'tipocomponente_id', referencedColumnName: 'id', nullable: false)]
    protected ?ServicioTipocomponente $tipocomponente = null;

    // Guardamos como string porque la columna es DECIMAL(4,1) y queremos evitar pérdidas por float.
    #[ORM\Column(type: 'decimal', precision: 4, scale: 1, nullable: true)]
    private ?string $duracion = null;

    /** Colección ordenada por 'nombre' ASC (lado 1:N con tarifas). */
    #[ORM\OneToMany(
        targetEntity: ServicioTarifa::class,
        mappedBy: 'componente',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    #[ORM\OrderBy(['nombre' => 'ASC'])]
    private Collection $tarifas;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $creado = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $modificado = null;

    public function __construct()
    {
        $this->tarifas = new ArrayCollection();
        $this->servicios = new ArrayCollection();
        $this->componenteitems = new ArrayCollection();
    }

    /**
     * Clonado profundo de tarifas y componenteitems; preserva enlaces M:N añadiendo este a cada servicio.
     * Importante: se limpian id/fechas para que Doctrine trate el clon como nuevo.
     */
    public function __clone(): void
    {
        if ($this->id) {
            $this->id = null;
            $this->setCreado(null);
            $this->setModificado(null);

            $newTarifas = new ArrayCollection();
            foreach ($this->tarifas as $tarifa) {
                $newTarifa = clone $tarifa;
                $newTarifa->setComponente($this);
                $newTarifas->add($newTarifa);
            }
            $this->tarifas = $newTarifas;

            $newServicios = new ArrayCollection();
            foreach ($this->servicios as $servicio) {
                $newServicio = $servicio; // mantenemos referencia y sumamos este componente
                $newServicio->addComponente($this);
                $newServicios->add($newServicio);
            }
            $this->servicios = $newServicios;

            $newComponenteitems = new ArrayCollection();
            foreach ($this->componenteitems as $componenteitem) {
                $newComponenteitem = clone $componenteitem;
                $newComponenteitem->setComponente($this);
                $newComponenteitems->add($newComponenteitem);
            }
            $this->componenteitems = $newComponenteitems;
        }
    }

    public function __toString(): string
    {
        return $this->getNombre() ?? sprintf('Id: %s.', (string) $this->getId()) ?? '';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setNombre(?string $nombre): self
    {
        $this->nombre = $nombre;
        return $this;
    }

    public function getNombre(): ?string
    {
        return $this->nombre;
    }

    public function setAnticipacionalerta(?int $anticipacionalerta): self
    {
        $this->anticipacionalerta = $anticipacionalerta;
        return $this;
    }

    public function getAnticipacionalerta(): ?int
    {
        return $this->anticipacionalerta;
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

    public function setTipocomponente(?ServicioTipocomponente $tipocomponente = null): self
    {
        $this->tipocomponente = $tipocomponente;
        return $this;
    }

    public function getTipocomponente(): ?ServicioTipocomponente
    {
        return $this->tipocomponente;
    }

    public function addTarifa(ServicioTarifa $tarifa): self
    {
        $tarifa->setComponente($this);
        $this->tarifas[] = $tarifa;
        return $this;
    }

    public function removeTarifa(ServicioTarifa $tarifa): void
    {
        $this->tarifas->removeElement($tarifa);
    }

    /** @return Collection<int, ServicioTarifa> */
    public function getTarifas(): Collection
    {
        return $this->tarifas;
    }

    public function addServicio(ServicioServicio $servicio): self
    {
        $servicio->addComponente($this);
        $this->servicios[] = $servicio;
        return $this;
    }

    public function removeServicio(ServicioServicio $servicio): void
    {
        $this->servicios->removeElement($servicio);
        $servicio->removeComponente($this);
    }

    /** @return Collection<int, ServicioServicio> */
    public function getServicios(): Collection
    {
        return $this->servicios;
    }

    public function setDuracion(?string $duracion = null): self
    {
        $this->duracion = $duracion;
        return $this;
    }

    public function getDuracion(): ?string
    {
        return $this->duracion;
    }

    public function addComponenteitem(ServicioComponenteitem $componenteitem): self
    {
        $componenteitem->setComponente($this);
        $this->componenteitems[] = $componenteitem;
        return $this;
    }

    public function removeComponenteitem(ServicioComponenteitem $componenteitem): void
    {
        $this->componenteitems->removeElement($componenteitem);
    }

    /** @return Collection<int, ServicioComponenteitem> */
    public function getComponenteitems(): Collection
    {
        return $this->componenteitems;
    }
}
