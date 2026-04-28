<?php

declare(strict_types=1);

namespace App\Travel\Entity;

use App\Attribute\AutoTranslate;
use App\Entity\Trait\AutoTranslateControlTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Travel\Enum\NotaTipoEnum;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entidad transversal que almacena información compartida (Historias, Políticas, Tips).
 * Evita la duplicidad de textos en el catálogo.
 */
#[ORM\Entity]
#[ORM\Table(name: 'travel_nota')]
class TravelNota
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait;

    #[ORM\Column(type: 'string', length: 150)]
    private ?string $nombreInterno = null;

    #[ORM\Column(type: 'string', length: 30, enumType: NotaTipoEnum::class)]
    private NotaTipoEnum $tipo = NotaTipoEnum::INTRODUCCION;

    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $titulo = [];

    #[AutoTranslate(sourceLanguage: 'es', format: 'html')]
    #[ORM\Column(type: 'json')]
    private array $contenido = [];

    /**
     * @var Collection<int, TravelItinerario>
     */
    #[ORM\ManyToMany(targetEntity: TravelItinerario::class, mappedBy: 'notas')]
    private Collection $itinerarios;

    public function __construct()
    {
        $this->initializeId();
        $this->itinerarios = new ArrayCollection();
    }

    public function __toString(): string
    {
        return sprintf('[%s] %s', strtoupper($this->tipo->value), $this->nombreInterno ?? 'Sin nombre');
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

    public function getTipo(): NotaTipoEnum
    {
        return $this->tipo;
    }

    public function setTipo(NotaTipoEnum $tipo): self
    {
        $this->tipo = $tipo;
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

    /**
     * @return Collection<int, TravelItinerario>
     */
    public function getItinerarios(): Collection
    {
        return $this->itinerarios;
    }

    public function addItinerario(TravelItinerario $itinerario): self
    {
        if (!$this->itinerarios->contains($itinerario)) {
            $this->itinerarios->add($itinerario);
            $itinerario->addNota($this);
        }
        return $this;
    }

    public function removeItinerario(TravelItinerario $itinerario): self
    {
        if ($this->itinerarios->removeElement($itinerario)) {
            $itinerario->removeNota($this);
        }
        return $this;
    }
}