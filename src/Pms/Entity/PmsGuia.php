<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Attribute\AutoTranslate;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Trait\AutoTranslateControlTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entidad que representa la guía de una unidad.
 * Utiliza UUID como identificador primario y auditoría inmutable.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pms_guia')]
#[ORM\HasLifecycleCallbacks]
class PmsGuia
{
    /**
     * Gestión de Identificador UUID (BINARY 16).
     */
    use IdTrait;

    /**
     * Gestión de auditoría temporal (DateTimeImmutable).
     */
    use TimestampTrait;

    /**
     * Trait global para el control de latencia en traducciones.
     * Inyecta el flag virtual 'ejecutarTraduccion'.
     */
    use AutoTranslateControlTrait;

    /**
     * Relación uno a uno con la unidad.
     * Se especifica BINARY(16) para coincidir con el IdTrait de PmsUnidad.
     */
    #[ORM\OneToOne(targetEntity: PmsUnidad::class, cascade: ['persist'])]
    #[ORM\JoinColumn(
        name: 'unidad_id',
        referencedColumnName: 'id',
        nullable: false,
        columnDefinition: 'BINARY(16) COMMENT "(DC2Type:uuid)"'
    )]
    private ?PmsUnidad $unidad = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $activo = true;

    /**
     * Campo JSON multiidioma con traducción automática.
     * @var array
     */
    #[ORM\Column(type: 'json')]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    private array $titulo = [];

    /**
     * Relación con la tabla de enlace para secciones compartidas.
     * @var Collection<int, PmsGuiaHasSeccion>
     */
    #[ORM\OneToMany(
        mappedBy: 'guia',
        targetEntity: PmsGuiaHasSeccion::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    #[ORM\OrderBy(['orden' => 'ASC'])]
    private Collection $guiaHasSecciones;

    /**
     * Constructor de la entidad.
     */
    public function __construct()
    {
        $this->guiaHasSecciones = new ArrayCollection();
        $this->titulo = [];
    }

    /**
     * Representación textual de la guía.
     */
    public function __toString(): string
    {
        return $this->unidad?->getNombre() ?? ('Guía UUID ' . $this->getId());
    }

    /*
     * -------------------------------------------------------------------------
     * GETTERS Y SETTERS EXPLÍCITOS (Regla 2026-01-14)
     * -------------------------------------------------------------------------
     */

    public function getUnidad(): ?PmsUnidad
    {
        return $this->unidad;
    }

    public function setUnidad(?PmsUnidad $unidad): self
    {
        $this->unidad = $unidad;
        return $this;
    }

    public function isActivo(): bool
    {
        return $this->activo;
    }

    public function setActivo(bool $activo): self
    {
        $this->activo = $activo;
        return $this;
    }

    /**
     * Getter para el título (JSON). Usado por el AutoTranslationEventListener.
     */
    public function getTitulo(): array
    {
        return $this->titulo;
    }

    /**
     * Setter para el título (JSON). Permite la inyección de traducciones.
     */
    public function setTitulo(array $titulo): self
    {
        $this->titulo = $titulo;
        return $this;
    }

    /**
     * @return Collection<int, PmsGuiaHasSeccion>
     */
    public function getGuiaHasSecciones(): Collection
    {
        return $this->guiaHasSecciones;
    }

    /**
     * Adder explícito para garantizar la relación bidireccional.
     */
    public function addGuiaHasSeccion(PmsGuiaHasSeccion $guiaHasSeccion): self
    {
        if (!$this->guiaHasSecciones->contains($guiaHasSeccion)) {
            $this->guiaHasSecciones->add($guiaHasSeccion);
            $guiaHasSeccion->setGuia($this);
        }
        return $this;
    }

    /**
     * Remover explícito para orphanRemoval.
     */
    public function removeGuiaHasSeccion(PmsGuiaHasSeccion $guiaHasSeccion): self
    {
        if ($this->guiaHasSecciones->removeElement($guiaHasSeccion)) {
            if ($guiaHasSeccion->getGuia() === $this) {
                $guiaHasSeccion->setGuia(null);
            }
        }
        return $this;
    }
}