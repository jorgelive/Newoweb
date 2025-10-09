<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Table(name: 'cot_menu')]
#[ORM\Entity]
#[Gedmo\TranslationEntity(class: CotizacionMenuTranslation::class)]
class CotizacionMenu
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue('AUTO')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 100, unique: true)]
    private string $nombre = '';

    #[Gedmo\Translatable]
    #[ORM\Column(type: 'string', length: 191)]
    private string $titulo = '';

    #[Gedmo\Translatable]
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $descripcion = null;

    /** @var Collection<int, CotizacionMenuTranslation> */
    #[ORM\OneToMany(mappedBy: 'object', targetEntity: CotizacionMenuTranslation::class, cascade: ['persist','remove'], orphanRemoval: true)]
    protected Collection $translations;

    /** @var Collection<int, CotizacionMenulink> */
    #[ORM\OneToMany(mappedBy: 'menu', targetEntity: CotizacionMenulink::class, cascade: ['persist','remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['posicion' => 'ASC', 'id' => 'ASC'])]
    private Collection $menulinks;

    // Timestampable NO NULL (consigna)
    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?\DateTimeInterface $creado = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?\DateTimeInterface $modificado = null;

    #[Gedmo\Locale]
    private ?string $locale = null;

    public function __construct()
    {
        $this->translations = new ArrayCollection();
        $this->menulinks        = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->titulo !== '' ? $this->titulo : ($this->nombre ?: 'Menu');
    }

    // Locale
    public function setLocale(?string $locale): self { $this->locale = $locale; return $this; }
    /** @return Collection<int, CotizacionMenuTranslation> */
    public function getTranslations(): Collection { return $this->translations; }
    public function addTranslation(CotizacionMenuTranslation $t): void
    {
        if (!$this->translations->contains($t)) { $this->translations->add($t); $t->setObject($this); }
    }

    // Id
    public function getId(): ?int { return $this->id; }

    // Campos
    public function getNombre(): string { return $this->nombre; }
    public function setNombre(string $nombre): self { $this->nombre = $nombre; return $this; }

    public function getTitulo(): string { return $this->titulo; }
    public function setTitulo(string $titulo): self { $this->titulo = $titulo; return $this; }

    public function getDescripcion(): ?string { return $this->descripcion; }
    public function setDescripcion(?string $descripcion): self { $this->descripcion = $descripcion; return $this; }

    /** @return Collection<int, CotizacionMenulink> */
    public function getMenulinks(): Collection { return $this->menulinks; }

    public function addCotizacion(CotizacionCotizacion $cotizacion, ?int $posicion = null): self
    {
        foreach ($this->menulinks as $link) {
            if ($link->getCotizacion() === $cotizacion) {
                if ($posicion !== null) { $link->setPosicion($posicion); }
                return $this;
            }
        }
        $link = (new CotizacionMenulink())
            ->setMenu($this)
            ->setCotizacion($cotizacion)
            ->setPosicion($posicion ?? ($this->menulinks->count() + 1));
        $this->menulinks->add($link);
        return $this;
    }

    public function removeCotizacion(CotizacionCotizacion $cotizacion): bool
    {
        foreach ($this->menulinks as $link) {
            if ($link->getCotizacion() === $cotizacion) {
                return $this->menulinks->removeElement($link);
            }
        }
        return false;
    }


    // Timestamps
    public function getCreado(): ?\DateTimeInterface { return $this->creado; }
    public function setCreado(?\DateTimeInterface $creado): self { $this->creado = $creado; return $this; }

    public function getModificado(): ?\DateTimeInterface { return $this->modificado; }
    public function setModificado(?\DateTimeInterface $modificado): self { $this->modificado = $modificado; return $this; }
}
