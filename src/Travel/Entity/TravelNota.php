<?php

declare(strict_types=1);

namespace App\Travel\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Attribute\AutoTranslate;
use App\Entity\Trait\AutoTranslateControlTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Security\Roles;
use App\Travel\Enum\NotaTipoEnum;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * Entidad transversal que almacena información compartida (Historias, Políticas, Tips).
 */
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['nota:read']],
            security: "is_granted('" . Roles::MAESTROS_SHOW . "')"
        ),
        new Get(
            normalizationContext: ['groups' => ['nota:item:read']],
            security: "is_granted('" . Roles::MAESTROS_SHOW . "')"
        ),
        new Post(
            denormalizationContext: ['groups' => ['nota:write']],
            securityPostDenormalize: "is_granted('" . Roles::MAESTROS_WRITE . "')",
            securityPostDenormalizeMessage: 'No tienes permiso para crear notas.'
        ),
        new Put(
            denormalizationContext: ['groups' => ['nota:write']],
            security: "is_granted('" . Roles::MAESTROS_WRITE . "')",
            securityMessage: 'No tienes permiso para editar notas.'
        ),
        new Delete(
            security: "is_granted('" . Roles::MAESTROS_DELETE . "')",
            securityMessage: 'No tienes permiso para eliminar notas.'
        )
    ]
)]
#[ORM\Entity]
#[ORM\Table(name: 'travel_nota')]
class TravelNota
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait;

    #[Groups(['nota:read', 'nota:item:read', 'nota:write'])]
    #[ORM\Column(type: 'string', length: 150)]
    private ?string $nombreInterno = null;

    #[Groups(['nota:read', 'nota:item:read', 'nota:write'])]
    #[ORM\Column(type: 'string', length: 30, enumType: NotaTipoEnum::class)]
    private NotaTipoEnum $tipo = NotaTipoEnum::INTRODUCCION;

    #[Groups(['nota:read', 'nota:item:read', 'nota:write'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $titulo = [];

    #[Groups(['nota:read', 'nota:item:read', 'nota:write'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'html')]
    #[ORM\Column(type: 'json')]
    private array $contenido = [];

    // 🚫 CORTE CIRCULAR
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