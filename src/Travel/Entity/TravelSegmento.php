<?php

declare(strict_types=1);

namespace App\Travel\Entity;

use App\Attribute\AutoTranslate;
use App\Entity\Trait\AutoTranslateControlTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * El bloque atómico de Storytelling (Párrafos, notas, descripciones narrativas).
 * Diseñado como una pieza de Lego reutilizable transversalmente.
 */
#[ORM\Entity]
#[ORM\Table(name: 'travel_segmento')]
class TravelSegmento
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait;

    /**
     * Los servicios (Bolsas/Pools) donde este segmento estará disponible.
     * Relación ManyToMany para permitir reciclar el segmento en múltiples tours.
     */
    #[ORM\ManyToMany(targetEntity: TravelServicio::class, inversedBy: 'segmentos')]
    #[ORM\JoinTable(name: 'travel_segmento_servicio_pool')]
    private Collection $servicios;

    #[ORM\Column(type: 'string', length: 150)]
    private ?string $nombreInterno = null;

    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $titulo = [];

    #[AutoTranslate(sourceLanguage: 'es', format: 'html')]
    #[ORM\Column(type: 'json')]
    private array $contenido = [];

    #[ORM\OneToMany(mappedBy: 'segmento', targetEntity: TravelSegmentoImagen::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $imagenes;

    /**
     * La lista de componentes logísticos (con su hora, servicio condicional y si están incluidos) que se ejecutan durante este párrafo.
     */
    #[ORM\OneToMany(mappedBy: 'segmento', targetEntity: TravelSegmentoComponente::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['orden' => 'ASC'])]
    private Collection $segmentoComponentes;

    public function __construct()
    {
        $this->initializeId();
        $this->servicios = new ArrayCollection();
        $this->imagenes = new ArrayCollection();
        $this->segmentoComponentes = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->nombreInterno ?? 'Sin nombre';
    }

    /**
     * @return Collection<int, TravelServicio>
     */
    public function getServicios(): Collection
    {
        return $this->servicios;
    }

    /**
     * Añade este segmento narrativo al pool de un servicio específico.
     */
    public function addServicio(TravelServicio $servicio): self
    {
        if (!$this->servicios->contains($servicio)) {
            $this->servicios->add($servicio);
        }
        return $this;
    }

    /**
     * Retira este segmento narrativo del pool de un servicio específico.
     */
    public function removeServicio(TravelServicio $servicio): self
    {
        $this->servicios->removeElement($servicio);
        return $this;
    }

    public function getNombreInterno(): ?string
    {
        return $this->nombreInterno;
    }

    public function setNombreInterno(string $nombreInterno): self
    {
        $this->nombreInterno = $nombreInterno;
        return $this;
    }

    public function getTitulo(): array
    {
        return $this->titulo;
    }

    public function setTitulo(array $titulo): self
    {
        $this->titulo = $titulo;
        return $this;
    }

    public function getContenido(): array
    {
        return $this->contenido;
    }

    public function setContenido(array $contenido): self
    {
        $this->contenido = $contenido;
        return $this;
    }

    public function getImagenes(): Collection
    {
        return $this->imagenes;
    }

    public function addImagen(TravelSegmentoImagen $imagen): self
    {
        if (!$this->imagenes->contains($imagen)) {
            $this->imagenes->add($imagen);
            $imagen->setSegmento($this);
        }
        return $this;
    }

    public function removeImagen(TravelSegmentoImagen $imagen): self
    {
        if ($this->imagenes->removeElement($imagen)) {
            if ($imagen->getSegmento() === $this) {
                $imagen->setSegmento(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, TravelSegmentoComponente>
     */
    public function getSegmentoComponentes(): Collection
    {
        return $this->segmentoComponentes;
    }

    public function addSegmentoComponente(TravelSegmentoComponente $segmentoComponente): self
    {
        if (!$this->segmentoComponentes->contains($segmentoComponente)) {
            $this->segmentoComponentes->add($segmentoComponente);
            $segmentoComponente->setSegmento($this);
        }
        return $this;
    }

    public function removeSegmentoComponente(TravelSegmentoComponente $segmentoComponente): self
    {
        if ($this->segmentoComponentes->removeElement($segmentoComponente)) {
            if ($segmentoComponente->getSegmento() === $this) {
                $segmentoComponente->setSegmento(null);
            }
        }
        return $this;
    }
}