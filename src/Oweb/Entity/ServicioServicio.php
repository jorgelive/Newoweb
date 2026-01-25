<?php
declare(strict_types=1);

namespace App\Oweb\Entity;

use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Table(name: 'ser_servicio')]
#[ORM\Entity]
#[Gedmo\TranslationEntity(class: 'App\Oweb\Entity\ServicioServicioTranslation')]
class ServicioServicio
{
    #[ORM\Column(type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    /** Colección de traducciones (mappedBy="object" en la entidad de traducción). */
    #[ORM\OneToMany(targetEntity: ServicioServicioTranslation::class, mappedBy: 'object', cascade: ['persist', 'remove'])]
    protected Collection $translations;

    #[ORM\Column(type: 'string', length: 20)]
    private ?string $codigo = null;

    #[ORM\Column(type: 'boolean', options: ['default' => 0])]
    private bool $paralelo = false;

    #[ORM\Column(type: 'string', length: 100)]
    private ?string $nombre = null;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $creado = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $modificado = null;

    #[ORM\ManyToMany(targetEntity: ServicioComponente::class, inversedBy: 'servicios')]
    #[ORM\JoinTable(name: 'servicio_componente')]
    #[ORM\JoinColumn(name: 'servicio_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'componente_id', referencedColumnName: 'id')]
    private Collection $componentes;

    /** Colección de itinerarios ordenados por 'nombre' ASC. */
    #[ORM\OneToMany(targetEntity: ServicioItinerario::class, mappedBy: 'servicio', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['nombre' => 'ASC'])]
    private Collection $itinerarios;

    #[Gedmo\Locale]
    private ?string $locale = null;

    public function __construct()
    {
        $this->componentes   = new ArrayCollection();
        $this->itinerarios   = new ArrayCollection();
        $this->translations  = new ArrayCollection();
    }

    /**
     * Clonado: se limpia id/fechas y se re-afilia este servicio en M:N.
     * Nota: no clonamos componentes/itinerarios; preservamos referencias según tu lógica actual.
     */
    public function __clone(): void
    {
        if ($this->id) {
            $this->id = null;
            $this->setCreado(null);
            $this->setModificado(null);

            $newComponentes = new ArrayCollection();
            foreach ($this->componentes as $componente) {
                // Importante (pitfall del owner de M:N):
                // No setear el componente desde aquí ni usar by_reference=false en el Admin
                // del lado propietario; solo añadimos este servicio al componente.
                $newComponente = $componente;
                $newComponente->addServicio($this);
                $newComponentes->add($newComponente);
            }
            $this->componentes = $newComponentes;
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

    public function setCodigo(string $codigo): self
    {
        $this->codigo = $codigo;
        return $this;
    }

    public function getCodigo(): ?string
    {
        return $this->codigo;
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

    /**
     * Importante: como owner de la M:N, no forces el inverso aquí ni uses by_reference=false.
     * Mantén la sincronización desde el otro lado si lo necesitas.
     */
    public function addComponente(ServicioComponente $componente): self
    {
        if (!$this->componentes->contains($componente)) {
            $this->componentes->add($componente);
        }
        return $this;
    }

    public function removeComponente(ServicioComponente $componente): void
    {
        $this->componentes->removeElement($componente);
    }

    /** @return Collection<int, ServicioComponente> */
    public function getComponentes(): Collection
    {
        return $this->componentes;
    }

    public function addItinerario(ServicioItinerario $itinerario): self
    {
        $itinerario->setServicio($this);
        $this->itinerarios->add($itinerario);
        return $this;
    }

    public function removeItinerario(ServicioItinerario $itinerario): void
    {
        $this->itinerarios->removeElement($itinerario);
    }

    /** @return Collection<int, ServicioItinerario> */
    public function getItinerarios(): Collection
    {
        return $this->itinerarios;
    }

    public function setParalelo(bool $paralelo): self
    {
        $this->paralelo = $paralelo;
        return $this;
    }

    public function isParalelo(): bool
    {
        return $this->paralelo;
    }
}
