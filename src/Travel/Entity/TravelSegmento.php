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
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ApiResource(
    shortName: 'Segmento',
    operations: [
        new GetCollection(normalizationContext: ['groups' => ['segmento:read']], security: "is_granted('" . Roles::MAESTROS_SHOW . "')"),
        new Get(normalizationContext: ['groups' => ['segmento:item:read']], security: "is_granted('" . Roles::MAESTROS_SHOW . "')"),
        new Post(denormalizationContext: ['groups' => ['segmento:write']], securityPostDenormalize: "is_granted('" . Roles::MAESTROS_WRITE . "')", securityPostDenormalizeMessage: 'No tienes permiso para crear segmentos.'),
        new Put(denormalizationContext: ['groups' => ['segmento:write']], security: "is_granted('" . Roles::MAESTROS_WRITE . "')", securityMessage: 'No tienes permiso para editar segmentos.'),
        new Delete(security: "is_granted('" . Roles::MAESTROS_DELETE . "')", securityMessage: 'No tienes permiso para eliminar segmentos.')
    ],
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

    #[Groups(['segmento:read', 'segmento:item:read', 'segmento:write', 'servicio:item:read'])]
    #[ORM\ManyToMany(targetEntity: TravelNota::class, inversedBy: 'segmentos')]
    #[ORM\JoinTable(name: 'travel_segmento_notas_rel')]
    private Collection $notas;

    #[Groups(['segmento:read', 'segmento:item:read', 'segmento:write'])]
    #[ORM\OneToMany(mappedBy: 'segmento', targetEntity: TravelSegmentoImagen::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $imagenes;

    #[Groups(['segmento:read', 'segmento:item:read', 'segmento:write', 'servicio:item:read'])]
    #[ORM\OneToMany(mappedBy: 'segmento', targetEntity: TravelSegmentoComponente::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['orden' => 'ASC'])]
    private Collection $segmentoComponentes;

    /**
     * 🔍 SOLO LECTURA: lado inverso para saber en qué Itinerarios (plantillas) y en qué
     * día se está inyectando este segmento. El dueño real es TravelItinerarioSegmentoRel.
     */
    #[ORM\OneToMany(mappedBy: 'segmento', targetEntity: TravelItinerarioSegmentoRel::class)]
    private Collection $itinerarioSegmentosInyectados;

    public function __construct()
    {
        $this->initializeId();
        $this->servicios = new ArrayCollection();
        $this->notas = new ArrayCollection();
        $this->imagenes = new ArrayCollection();
        $this->segmentoComponentes = new ArrayCollection();
        $this->itinerarioSegmentosInyectados = new ArrayCollection();
    }

    public function __clone()
    {
        $this->resetId();
        $this->resetTimestamps();

        if ($this->nombreInterno) {
            $this->nombreInterno = '(Clon) ' . $this->nombreInterno;
        }

        $serviciosOriginales = $this->servicios;
        $this->servicios = new ArrayCollection();
        foreach ($serviciosOriginales as $servicio) {
            $this->addServicio($servicio);
        }

        $notasOriginales = $this->notas;
        $this->notas = new ArrayCollection();
        foreach ($notasOriginales as $nota) {
            $this->addNota($nota);
        }

        $componentesOriginales = $this->segmentoComponentes;
        $this->segmentoComponentes = new ArrayCollection();
        foreach ($componentesOriginales as $compOriginal) {
            $clonComp = clone $compOriginal;
            $this->addSegmentoComponente($clonComp);
        }

        $imagenesOriginales = $this->imagenes;
        $this->imagenes = new ArrayCollection();
        foreach ($imagenesOriginales as $imgOriginal) {
            $clonImg = clone $imgOriginal;
            $this->addImagen($clonImg);
        }
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

    public function getServicios(): Collection
    {
        return $this->servicios;
    }

    public function addServicio(TravelServicio $servicio): self
    {
        if (!$this->servicios->contains($servicio)) {
            $this->servicios->add($servicio);
        }
        return $this;
    }

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

    public function getNotas(): Collection
    {
        return $this->notas;
    }

    public function addNota(TravelNota $nota): self
    {
        if (!$this->notas->contains($nota)) {
            $this->notas->add($nota);
        }
        return $this;
    }

    public function removeNota(TravelNota $nota): self
    {
        $this->notas->removeElement($nota);
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
     * @return Collection<int, TravelItinerarioSegmentoRel>
     */
    public function getItinerarioSegmentosInyectados(): Collection
    {
        return $this->itinerarioSegmentosInyectados;
    }

    // 🔥 VIRTUALES PARA EASYADMIN (TextField compatibles)
    public function getVirtualLogistica(): string { return ''; }
    public function getVirtualTitulo(): string { return ''; }
    public function getVirtualServicios(): string { return ''; }
    public function getVirtualNotas(): string { return ''; }
    public function getVirtualItinerarios(): string { return ''; }
    public function getVirtualGaleria(): string { return ''; }

    #[Assert\Callback]
    public function validateTituloEspanol(ExecutionContextInterface $context, mixed $payload): void
    {
        $hasValidSpanish = false;
        if (is_array($this->titulo)) {
            foreach ($this->titulo as $item) {
                if (isset($item['language'], $item['content']) && $item['language'] === 'es') {
                    if (trim(strip_tags((string) $item['content'])) !== '') {
                        $hasValidSpanish = true;
                        break;
                    }
                }
            }
        }
        if (!$hasValidSpanish) {
            $context->buildViolation('El título público en Español es obligatorio.')->atPath('titulo')->addViolation();
        }
    }
}