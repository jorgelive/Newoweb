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
use Symfony\Component\Uid\Uuid;

#[ApiResource(
    shortName: 'Segmento',  // 🔥 Define el recurso base para generar '/segmentos'
    operations: [
        // Genera: GET /travel/segmentos
        new GetCollection(
            normalizationContext: ['groups' => ['segmento:read']],
            security: "is_granted('" . Roles::MAESTROS_SHOW . "')"
        ),

        // Genera: GET /travel/segmentos/{id}
        new Get(
            normalizationContext: ['groups' => ['segmento:item:read']],
            security: "is_granted('" . Roles::MAESTROS_SHOW . "')"
        ),

        // Genera: POST /travel/segmentos
        new Post(
            denormalizationContext: ['groups' => ['segmento:write']],
            securityPostDenormalize: "is_granted('" . Roles::MAESTROS_WRITE . "')",
            securityPostDenormalizeMessage: 'No tienes permiso para crear segmentos.'
        ),

        // Genera: PUT /travel/segmentos/{id}
        new Put(
            denormalizationContext: ['groups' => ['segmento:write']],
            security: "is_granted('" . Roles::MAESTROS_WRITE . "')",
            securityMessage: 'No tienes permiso para editar segmentos.'
        ),

        // Genera: DELETE /travel/segmentos/{id}
        new Delete(
            security: "is_granted('" . Roles::MAESTROS_DELETE . "')",
            securityMessage: 'No tienes permiso para eliminar segmentos.'
        )
    ], // 🔥 Agrupa todas las rutas bajo el módulo logístico
    routePrefix: '/travel'
)]
#[ORM\Entity]
#[ORM\Table(name: 'travel_segmento')]
#[ORM\HasLifecycleCallbacks]
class TravelSegmento
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait;

    // 🚫 CORTE CIRCULAR
    #[ORM\ManyToMany(targetEntity: TravelServicio::class, inversedBy: 'segmentos')]
    #[ORM\JoinTable(name: 'travel_segmento_servicio_pool')]
    private Collection $servicios;

    #[Groups(['segmento:read', 'segmento:item:read', 'segmento:write', 'servicio:item:read'])]
    #[ORM\Column(type: 'string', length: 150)]
    private ?string $nombreInterno = null;

    #[Groups(['segmento:read', 'segmento:item:read', 'segmento:write', 'servicio:item:read'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $titulo = [];

    #[Groups(['segmento:read', 'segmento:item:read', 'segmento:write', 'servicio:item:read'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'html')]
    #[ORM\Column(type: 'json')]
    private array $contenido = [];

    // 👇 CASCADA HACIA ABAJO
    #[Groups(['segmento:item:read', 'segmento:write'])]
    #[ORM\OneToMany(mappedBy: 'segmento', targetEntity: TravelSegmentoImagen::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $imagenes;

    // 👇 CASCADA HACIA ABAJO
    #[Groups(['segmento:item:read', 'segmento:write', 'servicio:item:read'])]
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

    #[Groups(['segmento:read', 'segmento:item:read', 'servicio:item:read', 'cotizacion:read'])]
    public function getId(): ?Uuid
    {
        return $this->id;
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
    /**
     * Campo virtual para EasyAdmin.
     * Retorna un string vacío para engañar al validador estricto de TextField,
     * permitiendo que el CRUD Controller inyecte el HTML personalizado sin colapsar.
     */
    public function getVirtualLogistica(): string
    {
        return '';
    }
}