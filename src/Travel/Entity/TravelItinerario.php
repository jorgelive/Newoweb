<?php

declare(strict_types=1);

namespace App\Travel\Entity;

use ApiPlatform\Metadata\ApiProperty;
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
    shortName: 'Itinerario',
    operations: [
        new GetCollection(normalizationContext: ['groups' => ['itinerario:read']], security: "is_granted('" . Roles::MAESTROS_SHOW . "')"),
        new Get(normalizationContext: ['groups' => ['itinerario:item:read']], security: "is_granted('" . Roles::MAESTROS_SHOW . "')"),
        new Post(denormalizationContext: ['groups' => ['itinerario:write']], securityPostDenormalize: "is_granted('" . Roles::MAESTROS_WRITE . "')", securityPostDenormalizeMessage: 'No tienes permiso para crear itinerarios.'),
        new Put(denormalizationContext: ['groups' => ['itinerario:write']], security: "is_granted('" . Roles::MAESTROS_WRITE . "')", securityMessage: 'No tienes permiso para editar itinerarios.'),
        new Delete(security: "is_granted('" . Roles::MAESTROS_DELETE . "')", securityMessage: 'No tienes permiso para eliminar itinerarios.')
    ],
    routePrefix: '/travel'
)]
#[ORM\Entity]
#[ORM\Table(name: 'travel_itinerario')]
#[ORM\HasLifecycleCallbacks]
class TravelItinerario
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait;

    #[Groups(['itinerario:read', 'itinerario:item:read', 'itinerario:write'])]
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: TravelServicio::class, inversedBy: 'itinerarios')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?TravelServicio $servicio = null;

    /**
     * Código de identificación de la plantilla (ej. `HD-COMBINADA-POOL-AM`). El SLUG.
     *
     * Se separó de `nombreInterno` (2026-08-17): éste último quedó como el código que Jorge usa
     * para identificar, y `nombreInterno` pasa a ser un nombre operativo de verdad. Nace copiado
     * del código por migración; se edita después.
     */
    #[Groups(['itinerario:read', 'itinerario:item:read', 'itinerario:write', 'servicio:item:read'])]
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $slug = null;

    #[Groups(['itinerario:read', 'itinerario:item:read', 'itinerario:write', 'servicio:item:read', 'segmento:read', 'segmento:item:read'])]
    #[ORM\Column(type: 'string', length: 150)]
    private ?string $nombreInterno = null;

    /** @var list<array{language?: string, content?: string|null}> */
    #[Groups(['itinerario:read', 'itinerario:item:read', 'itinerario:write','servicio:item:read'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $titulo = [];

    #[Groups(['itinerario:read', 'itinerario:item:read', 'itinerario:write'])]
    #[ORM\Column(type: 'integer')]
    private int $duracionDias = 1;

    /** @var Collection<int, TravelItinerarioSegmentoRel> */
    #[Groups(['itinerario:item:read', 'itinerario:write'])]
    #[ORM\OneToMany(mappedBy: 'itinerario', targetEntity: TravelItinerarioSegmentoRel::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['dia' => 'ASC', 'orden' => 'ASC'])]
    private Collection $itinerarioSegmentos;

    public function __construct()
    {
        $this->initializeId();
        $this->itinerarioSegmentos = new ArrayCollection();
    }

    public function __clone()
    {
        $this->resetId();
        $this->resetTimestamps();

        if ($this->nombreInterno) {
            $this->nombreInterno = '(Clon) ' . $this->nombreInterno;
        }

        $segmentosOriginales = $this->itinerarioSegmentos;
        $this->itinerarioSegmentos = new ArrayCollection();
        foreach ($segmentosOriginales as $segmentoOriginal) {
            $clonSegmento = clone $segmentoOriginal;
            $this->addItinerarioSegmento($clonSegmento);
        }
    }

    #[Groups(['itinerario:read', 'itinerario:item:read', 'servicio:item:read', 'cotizacion:read', 'segmento:read', 'segmento:item:read'])]
    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function __toString(): string
    {
        return $this->nombreInterno ?? 'Sin nombre';
    }

    public function getServicio(): ?TravelServicio
    {
        return $this->servicio;
    }

    public function setServicio(?TravelServicio $servicio): self
    {
        $this->servicio = $servicio;
        return $this;
    }

    public function getSlug(): ?string { return $this->slug; }
    public function setSlug(?string $slug): self { $this->slug = $slug; return $this; }

    public function getNombreInterno(): ?string
    {
        return $this->nombreInterno;
    }

    public function setNombreInterno(string $nombreInterno): self
    {
        $this->nombreInterno = $nombreInterno;
        return $this;
    }

    /**
     * @return list<array{language?: string, content?: string|null}>
     */
    public function getTitulo(): array
    {
        return $this->titulo;
    }

    /**
     * @param list<array{language?: string, content?: string|null}> $titulo
     */
    public function setTitulo(array $titulo): self
    {
        $this->titulo = $titulo;
        return $this;
    }

    public function getDuracionDias(): int
    {
        return $this->duracionDias;
    }

    public function setDuracionDias(int $duracionDias): self
    {
        $this->duracionDias = $duracionDias;
        return $this;
    }

    /**
     * @return Collection<int, TravelItinerarioSegmentoRel>
     */
    public function getItinerarioSegmentos(): Collection
    {
        return $this->itinerarioSegmentos;
    }

    public function addItinerarioSegmento(TravelItinerarioSegmentoRel $itinerarioSegmento): self
    {
        if (!$this->itinerarioSegmentos->contains($itinerarioSegmento)) {
            $this->itinerarioSegmentos->add($itinerarioSegmento);
            $itinerarioSegmento->setItinerario($this);
        }
        return $this;
    }

    public function removeItinerarioSegmento(TravelItinerarioSegmentoRel $itinerarioSegmento): self
    {
        if ($this->itinerarioSegmentos->removeElement($itinerarioSegmento)) {
            if ($itinerarioSegmento->getItinerario() === $this) {
                $itinerarioSegmento->setItinerario(null);
            }
        }
        return $this;
    }

    // 🔥 VIRTUALES PARA EASYADMIN (TextField compatibles)
    public function getVirtualTitulo(): string { return ''; }
    public function getVirtualSegmentos(): string { return ''; }

    #[Assert\Callback]
    public function validateTituloEspanol(ExecutionContextInterface $context, mixed $payload): void
    {
        $hasValidSpanish = false;

        // Sin `is_array()`: la propiedad ya está declarada como lista de traducciones.
        foreach ($this->titulo as $item) {
            if (isset($item['language'], $item['content']) && $item['language'] === 'es') {
                if (trim(strip_tags((string) $item['content'])) !== '') {
                    $hasValidSpanish = true;
                    break;
                }
            }
        }
        if (!$hasValidSpanish) {
            $context->buildViolation('El título público en Español es obligatorio.')->atPath('titulo')->addViolation();
        }
    }
}