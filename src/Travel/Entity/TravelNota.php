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
 * Ahora vinculada dinámicamente a Nivel de Segmento.
 */
#[ApiResource(
    shortName: 'Nota',
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
    ],
    routePrefix: '/travel'
)]
#[ORM\Entity]
#[ORM\Table(name: 'travel_nota')]
#[ORM\HasLifecycleCallbacks]
class TravelNota
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait;

    #[Groups(['nota:read', 'nota:item:read', 'nota:write', 'segmento:read', 'segmento:item:read', 'servicio:item:read'])]
    #[ORM\Column(type: 'string', length: 150)]
    private ?string $nombreInterno = null;

    #[Groups(['nota:read', 'nota:item:read', 'nota:write', 'segmento:read', 'segmento:item:read', 'servicio:item:read'])]
    #[ORM\Column(type: 'string', length: 30, enumType: NotaTipoEnum::class)]
    private NotaTipoEnum $tipo = NotaTipoEnum::INTRODUCCION;

    #[Groups(['nota:read', 'nota:item:read', 'nota:write', 'segmento:read', 'segmento:item:read', 'servicio:item:read'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $titulo = [];

    #[Groups(['nota:read', 'nota:item:read', 'nota:write', 'segmento:read', 'segmento:item:read', 'servicio:item:read'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'html')]
    #[ORM\Column(type: 'json')]
    private array $contenido = [];

    // 🚫 CORTE CIRCULAR: El Segmento es el propietario de la relación
    /**
     * @var Collection<int, TravelSegmento>
     */
    #[ORM\ManyToMany(targetEntity: TravelSegmento::class, mappedBy: 'notas')]
    private Collection $segmentos;

    public function __construct()
    {
        $this->initializeId();
        $this->segmentos = new ArrayCollection();
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
     * @return Collection<int, TravelSegmento>
     */
    public function getSegmentos(): Collection
    {
        return $this->segmentos;
    }

    public function addSegmento(TravelSegmento $segmento): self
    {
        if (!$this->segmentos->contains($segmento)) {
            $this->segmentos->add($segmento);
            $segmento->addNota($this);
        }
        return $this;
    }

    public function removeSegmento(TravelSegmento $segmento): self
    {
        if ($this->segmentos->removeElement($segmento)) {
            $segmento->removeNota($this);
        }
        return $this;
    }

    /**
     * Muestra el título visible al cliente en español directamente en la lista.
     * Estructura real: [{"language": "es", "content": "..."}, ...]
     */
    public function getVirtualTituloEs(): string
    {
        foreach ($this->titulo as $entrada) {
            if (($entrada['language'] ?? null) === 'es') {
                return $entrada['content'] ?? '—';
            }
        }

        return '—';
    }

    /**
     * Muestra un preview corto del cuerpo en español (el contenido es HTML,
     * así que lo despojamos de tags para no romper el layout del listado).
     */
    public function getVirtualContenidoEs(): string
    {
        foreach ($this->contenido as $entrada) {
            if (($entrada['language'] ?? null) === 'es') {
                $texto = strip_tags($entrada['content'] ?? '');
                return mb_strlen($texto) > 120
                    ? mb_substr($texto, 0, 120) . '…'
                    : ($texto ?: '—');
            }
        }

        return '—';
    }

}