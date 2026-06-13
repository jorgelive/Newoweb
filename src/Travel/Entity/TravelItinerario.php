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
// 🔥 NUEVOS IMPORTS PARA LA VALIDACIÓN
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ApiResource(
    shortName: 'Itinerario', // 🔥 Define el recurso base para generar '/itinerarios'
    operations: [
        // Genera: GET /travel/itinerarios
        new GetCollection(
            normalizationContext: ['groups' => ['itinerario:read']],
            security: "is_granted('" . Roles::MAESTROS_SHOW . "')"
        ),

        // Genera: GET /travel/itinerarios/{id}
        new Get(
            normalizationContext: ['groups' => ['itinerario:item:read']],
            security: "is_granted('" . Roles::MAESTROS_SHOW . "')"
        ),

        // Genera: POST /travel/itinerarios
        new Post(
            denormalizationContext: ['groups' => ['itinerario:write']],
            securityPostDenormalize: "is_granted('" . Roles::MAESTROS_WRITE . "')",
            securityPostDenormalizeMessage: 'No tienes permiso para crear itinerarios.'
        ),

        // Genera: PUT /travel/itinerarios/{id}
        new Put(
            denormalizationContext: ['groups' => ['itinerario:write']],
            security: "is_granted('" . Roles::MAESTROS_WRITE . "')",
            securityMessage: 'No tienes permiso para editar itinerarios.'
        ),

        // Genera: DELETE /travel/itinerarios/{id}
        new Delete(
            security: "is_granted('" . Roles::MAESTROS_DELETE . "')",
            securityMessage: 'No tienes permiso para eliminar itinerarios.'
        )
    ],  // 🔥 Agrupa todas las rutas bajo el módulo logístico
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

    #[Groups(['itinerario:read', 'itinerario:item:read', 'itinerario:write', 'servicio:item:read', 'segmento:item:read'])]
    #[ORM\Column(type: 'string', length: 150)]
    private ?string $nombreInterno = null;

    #[Groups(['itinerario:read', 'itinerario:item:read', 'itinerario:write','servicio:item:read'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $titulo = [];

    #[Groups(['itinerario:read', 'itinerario:item:read', 'itinerario:write'])]
    #[ORM\Column(type: 'integer')]
    private int $duracionDias = 1;

    // 👇 CASCADA HACIA ABAJO (Segmentos ordenados por día)
    #[Groups(['itinerario:item:read', 'itinerario:write'])]
    #[ORM\OneToMany(mappedBy: 'itinerario', targetEntity: TravelItinerarioSegmentoRel::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['dia' => 'ASC', 'orden' => 'ASC'])]
    private Collection $itinerarioSegmentos;

    public function __construct()
    {
        $this->initializeId();
        $this->itinerarioSegmentos = new ArrayCollection();
    }

    #[Groups(['itinerario:read', 'itinerario:item:read', 'servicio:item:read', 'cotizacion:read', 'segmento:item:read'])]
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

    public function getDuracionDias(): int
    {
        return $this->duracionDias;
    }

    public function setDuracionDias(int $duracionDias): self
    {
        $this->duracionDias = $duracionDias;
        return $this;
    }

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

    /**
     * 🔥 NUEVO: Valida que el arreglo JSON de Título contenga el idioma Español ('es')
     * y que este no esté en blanco.
     * EasyAdmin llamará a este método automáticamente al intentar guardar el formulario.
     */
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
            $context->buildViolation('El título público en Español es obligatorio.')
                ->atPath('titulo') // Marca el campo en rojo en EasyAdmin
                ->addViolation();
        }
    }
}