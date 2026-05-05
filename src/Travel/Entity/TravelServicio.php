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
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * Actúa como una bolsa/pool que agrupa componentes logísticos y segmentos narrativos
 * para que luego las plantillas de itinerario y las cotizaciones los utilicen.
 */
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['servicio:read']],
            security: "is_granted('" . Roles::MAESTROS_SHOW . "')"
        ),
        new Get(
            normalizationContext: ['groups' => ['servicio:item:read']],
            security: "is_granted('" . Roles::MAESTROS_SHOW . "')"
        ),
        new Post(
            denormalizationContext: ['groups' => ['servicio:write']],
            securityPostDenormalize: "is_granted('" . Roles::MAESTROS_WRITE . "')",
            securityPostDenormalizeMessage: 'No tienes permiso para crear servicios.'
        ),
        new Put(
            denormalizationContext: ['groups' => ['servicio:write']],
            security: "is_granted('" . Roles::MAESTROS_WRITE . "')",
            securityMessage: 'No tienes permiso para editar servicios.'
        ),
        new Delete(
            security: "is_granted('" . Roles::MAESTROS_DELETE . "')",
            securityMessage: 'No tienes permiso para eliminar servicios.'
        )
    ]
)]
#[ORM\Entity]
#[ORM\Table(name: 'travel_servicio')]
class TravelServicio
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait;

    #[Groups(['servicio:read', 'servicio:item:read', 'servicio:write'])]
    #[ORM\Column(type: 'string', length: 150)]
    private ?string $nombreInterno = null;

    #[Groups(['servicio:read', 'servicio:item:read', 'servicio:write'])]
    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $codigo = null;

    #[Groups(['servicio:read', 'servicio:item:read', 'servicio:write'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $titulo = [];

    /**
     * Pool logístico. Solo lectura profunda, al escribir pasamos IRIs.
     */
    #[Groups(['servicio:item:read', 'servicio:write'])]
    #[ORM\ManyToMany(targetEntity: TravelComponente::class, inversedBy: 'servicios')]
    #[ORM\JoinTable(name: 'travel_servicio_componentes_pool')]
    private Collection $componentes;

    /**
     * Itinerarios pre-armados. Solo lectura profunda, al escribir pasamos IRIs.
     */
    #[Groups(['servicio:item:read', 'servicio:write'])]
    #[ORM\OneToMany(mappedBy: 'servicio', targetEntity: TravelItinerario::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $itinerarios;

    /**
     * Pool narrativo.
     */
    #[Groups(['servicio:item:read', 'servicio:write'])]
    #[ORM\ManyToMany(targetEntity: TravelSegmento::class, mappedBy: 'servicios')]
    private Collection $segmentos;

    public function __construct()
    {
        $this->initializeId();
        $this->componentes = new ArrayCollection();
        $this->itinerarios = new ArrayCollection();
        $this->segmentos = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->nombreInterno ?? 'Sin nombre';
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

    public function getCodigo(): ?string
    {
        return $this->codigo;
    }

    public function setCodigo(?string $codigo): self
    {
        $this->codigo = $codigo;
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

    public function getComponentes(): Collection
    {
        return $this->componentes;
    }

    public function addComponente(TravelComponente $componente): self
    {
        if (!$this->componentes->contains($componente)) {
            $this->componentes->add($componente);
        }
        return $this;
    }

    public function removeComponente(TravelComponente $componente): self
    {
        $this->componentes->removeElement($componente);
        return $this;
    }

    public function getItinerarios(): Collection
    {
        return $this->itinerarios;
    }

    public function addItinerario(TravelItinerario $itinerario): self
    {
        if (!$this->itinerarios->contains($itinerario)) {
            $this->itinerarios->add($itinerario);
            $itinerario->setServicio($this);
        }
        return $this;
    }

    public function removeItinerario(TravelItinerario $itinerario): self
    {
        if ($this->itinerarios->removeElement($itinerario)) {
            if ($itinerario->getServicio() === $this) {
                $itinerario->setServicio(null);
            }
        }
        return $this;
    }

    public function getSegmentos(): Collection
    {
        return $this->segmentos;
    }

    /**
     * Vincula un segmento narrativo al pool de este servicio.
     */
    public function addSegmento(TravelSegmento $segmento): self
    {
        if (!$this->segmentos->contains($segmento)) {
            $this->segmentos->add($segmento);
            $segmento->addServicio($this);
        }
        return $this;
    }

    /**
     * Desvincula un segmento narrativo del pool de este servicio.
     */
    public function removeSegmento(TravelSegmento $segmento): self
    {
        if ($this->segmentos->removeElement($segmento)) {
            $segmento->removeServicio($this);
        }
        return $this;
    }
}