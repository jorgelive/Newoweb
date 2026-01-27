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
 * Entidad PmsGuiaSeccion.
 * Agrupa ítems de contenido (tarjetas, wifi, videos) en la guía digital.
 * Permite definir secciones comunes compartibles entre múltiples unidades.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pms_guia_seccion')]
#[ORM\HasLifecycleCallbacks]
class PmsGuiaSeccion
{
    /** Gestión de Identificador UUID (BINARY 16) */
    use IdTrait;

    /** Gestión de auditoría temporal (DateTimeImmutable) */
    use TimestampTrait;

    /**
     * Trait global para el control de latencia en traducciones.
     * Permite activar/desactivar la traducción automática desde el formulario.
     */
    use AutoTranslateControlTrait;

    /**
     * Título multiidioma procesado por el núcleo global de traducción.
     * @var array
     */
    #[ORM\Column(type: 'json')]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    private array $titulo = [];

    /**
     * Identificador del icono (ej: 'info', 'wifi', 'map').
     */
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $icono = 'info';

    /**
     * Si es TRUE, esta sección aparecerá disponible para ser vinculada a cualquier guía.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $esComun = false;

    /**
     * Colección de ítems de contenido.
     * @var Collection<int, PmsGuiaItem>
     */
    #[ORM\OneToMany(
        mappedBy: 'seccion',
        targetEntity: PmsGuiaItem::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    #[ORM\OrderBy(['orden' => 'ASC'])]
    private Collection $items;

    /**
     * Constructor de la entidad.
     */
    public function __construct()
    {
        $this->items = new ArrayCollection();
        $this->titulo = [];
    }

    /**
     * Representación textual de la sección.
     */
    public function __toString(): string
    {
        $prefix = $this->esComun ? '[COMÚN] ' : '';
        $nombre = $this->titulo['es'] ?? ('Sección UUID ' . $this->getId());
        return $prefix . $nombre;
    }

    /*
     * -------------------------------------------------------------------------
     * GETTERS Y SETTERS EXPLÍCITOS (Regla 2026-01-14)
     * -------------------------------------------------------------------------
     */

    /**
     * @return array
     */
    public function getTitulo(): array
    {
        return $this->titulo;
    }

    /**
     * @param array $titulo
     * @return self
     */
    public function setTitulo(array $titulo): self
    {
        $this->titulo = $titulo;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getIcono(): ?string
    {
        return $this->icono;
    }

    /**
     * @param string|null $icono
     * @return self
     */
    public function setIcono(?string $icono): self
    {
        $this->icono = $icono;
        return $this;
    }

    /**
     * @return bool
     */
    public function isEsComun(): bool
    {
        return $this->esComun;
    }

    /**
     * @param bool $esComun
     * @return self
     */
    public function setEsComun(bool $esComun): self
    {
        $this->esComun = $esComun;
        return $this;
    }

    /**
     * @return Collection<int, PmsGuiaItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    /**
     * Adder explícito para mantener la integridad de la relación OneToMany.
     */
    public function addItem(PmsGuiaItem $item): self
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setSeccion($this);
        }
        return $this;
    }

    /**
     * Remover explícito para facilitar orphanRemoval por parte de Doctrine.
     */
    public function removeItem(PmsGuiaItem $item): self
    {
        if ($this->items->removeElement($item)) {
            if ($item->getSeccion() === $this) {
                // Relación obligatoria gestionada por orphanRemoval
            }
        }
        return $this;
    }
}