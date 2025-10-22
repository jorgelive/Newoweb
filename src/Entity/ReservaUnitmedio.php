<?php

namespace App\Entity;

use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use App\Traits\MainArchivoTrait;

#[ORM\Entity]
#[ORM\Table(name: 'res_unitmedio')]
#[ORM\HasLifecycleCallbacks]
#[Gedmo\TranslationEntity(class: ReservaUnitmedioTranslation::class)]
class ReservaUnitmedio
{
    use MainArchivoTrait;

    /**
     * Ruta base para archivos (no persistida).
     */
    private string $path = '/carga/reservaunitmedio';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /**
     * Relación con las traducciones (Gedmo).
     * - mappedBy "object" debe existir en la entidad Translation.
     */
    #[ORM\OneToMany(
        mappedBy: 'object',
        targetEntity: ReservaUnitmedioTranslation::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    protected Collection $translations;

    #[Gedmo\Translatable]
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $titulo = null;

    /**
     * NUEVA RELACIÓN: hijo de la característica.
     */
    #[ORM\ManyToOne(
        targetEntity: ReservaUnitcaracteristica::class,
        inversedBy: 'medios'
    )]
    #[ORM\JoinColumn(
        name: 'unitcaracteristica_id',
        referencedColumnName: 'id',
        nullable: true,
        onDelete: 'SET NULL'
    )]
    protected ?ReservaUnitcaracteristica $unitcaracteristica = null;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $creado = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $modificado = null;

    #[Gedmo\Locale]
    private ?string $locale = null;

    public function __construct()
    {
        $this->translations = new ArrayCollection();
    }

    public function __toString(): string
    {
        $id = $this->id ?? 0;

        $tipo = null;
        if ($this->unitcaracteristica) {
            $tipoObj = $this->unitcaracteristica->getUnittipocaracteristica();
            $tipo = $tipoObj ? $tipoObj->getNombre() : null;
        }

        return $tipo
            ? sprintf('Medio #%d · tipo: %s', $id, $tipo)
            : sprintf('Medio #%d', $id);
    }

    // Locale
    public function setLocale(?string $locale): self
    {
        $this->locale = $locale;
        return $this;
    }

    // Translations
    /** @return Collection<int,ReservaUnitmedioTranslation> */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(ReservaUnitmedioTranslation $t): self
    {
        if (!$this->translations->contains($t)) {
            $this->translations->add($t);
            $t->setObject($this);
        }
        return $this;
    }

    public function removeTranslation(ReservaUnitmedioTranslation $t): self
    {
        if ($this->translations->removeElement($t)) {
            if ($t->getObject() === $this) {
                $t->setObject(null);
            }
        }
        return $this;
    }

    // Id
    public function getId(): ?int
    {
        return $this->id;
    }

    // Titulo
    public function setTitulo(?string $titulo): self
    {
        $this->titulo = $titulo;
        return $this;
    }

    public function getTitulo(): ?string
    {
        return $this->titulo;
    }

    // Relación característica
    public function getUnitcaracteristica(): ?ReservaUnitcaracteristica
    {
        return $this->unitcaracteristica;
    }

    public function setUnitcaracteristica(?ReservaUnitcaracteristica $c): self
    {
        $this->unitcaracteristica = $c;
        return $this;
    }

    // Timestamps
    public function setCreado(?DateTimeInterface $creado): self
    {
        $this->creado = $creado;
        return $this;
    }

    public function getCreado(): ?DateTimeInterface
    {
        return $this->creado;
    }

    public function setModificado(?DateTimeInterface $modificado): self
    {
        $this->modificado = $modificado;
        return $this;
    }

    public function getModificado(): ?DateTimeInterface
    {
        return $this->modificado;
    }
}
