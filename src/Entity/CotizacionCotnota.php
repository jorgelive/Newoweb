<?php

namespace App\Entity;

use App\Entity\CotizacionCotizacion;
use App\Entity\CotizacionCotnotaTranslation;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Translatable\Translatable;

#[ORM\Table(name: 'cot_cotnota')]
#[ORM\Entity]
#[Gedmo\TranslationEntity(class: CotizacionCotnotaTranslation::class)]
class CotizacionCotnota implements Translatable
{
    #[ORM\Column(type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    #[ORM\Column(name: 'nombre', type: 'string', length: 255)]
    private ?string $nombre = null;

    // Translatable siempre con definición de columna (consigna)
    #[Gedmo\Translatable]
    #[ORM\Column(name: 'titulo', type: 'string', length: 100, nullable: true)]
    private ?string $titulo = null;

    #[Gedmo\Translatable]
    #[ORM\Column(name: 'contenido', type: 'text', nullable: true)]
    private ?string $contenido = null;

    /**
     * Lado inverso (propietario: CotizacionCotizacion::$cotnotas). No usar JoinTable aquí.
     * @var Collection<int, CotizacionCotizacion>
     */
    #[ORM\ManyToMany(targetEntity: CotizacionCotizacion::class, mappedBy: 'cotnotas', cascade: ['persist', 'remove'])]
    private Collection $cotizaciones;

    /**
     * Relación inversa a las traducciones (Gedmo PersonalTranslation).
     * Consigna: en la entidad de traducción no tipar $object ni el setter.
     * @var Collection<int, CotizacionCotnotaTranslation>
     */
    #[ORM\OneToMany(targetEntity: CotizacionCotnotaTranslation::class, mappedBy: 'object', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $translations;

    // Timestampable NO nullable (consigna)
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
        $this->cotizaciones = new ArrayCollection();
        $this->translations = new ArrayCollection();
    }

    // ================= i18n =================

    public function setLocale(?string $locale): self { $this->locale = $locale; return $this; }

    /** @return Collection<int, CotizacionCotnotaTranslation> */
    public function getTranslations(): Collection { return $this->translations; }

    public function addTranslation(CotizacionCotnotaTranslation $translation): self
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setObject($this);
        }
        return $this;
    }

    // Consigna Gedmo: NO llamar setObject(null); confiar en orphanRemoval=true
    public function removeTranslation(CotizacionCotnotaTranslation $translation): self
    {
        $this->translations->removeElement($translation);
        return $this;
    }

    // ================= Basics =================

    public function __toString(): string
    {
        return $this->getNombre() ?? sprintf('Id: %s.', $this->getId() ?? '');
    }

    public function getId(): ?int { return $this->id; }

    public function setNombre(string $nombre): self { $this->nombre = $nombre; return $this; }
    public function getNombre(): ?string { return $this->nombre; }

    public function setTitulo(?string $titulo): self { $this->titulo = $titulo; return $this; }
    public function getTitulo(): ?string { return $this->titulo; }

    public function setContenido(?string $contenido): self { $this->contenido = $contenido; return $this; }
    public function getContenido(): ?string { return $this->contenido; }

    public function getCreado(): ?\DateTimeInterface { return $this->creado; }
    public function setCreado(?\DateTimeInterface $creado): self { $this->creado = $creado; return $this; }

    public function getModificado(): ?\DateTimeInterface { return $this->modificado; }
    public function setModificado(?\DateTimeInterface $modificado): self { $this->modificado = $modificado; return $this; }

    /** @return Collection<int, CotizacionCotizacion> */
    public function getCotizaciones(): Collection { return $this->cotizaciones; }

    public function addCotizacion(CotizacionCotizacion $cotizacion): self
    {
        if (!$this->cotizaciones->contains($cotizacion)) {
            $this->cotizaciones->add($cotizacion);
            if (method_exists($cotizacion, 'addCotnota')) {
                $cotizacion->addCotnota($this);
            }
        }
        return $this;
    }

    // alias por inflector inglés
    public function addCotizacione(CotizacionCotizacion $cotizacion): self
    { return $this->addCotizacion($cotizacion); }

    public function removeCotizacion(CotizacionCotizacion $cotizacion): self
    {
        if ($this->cotizaciones->removeElement($cotizacion)) {
            if (method_exists($cotizacion, 'removeCotnota')) {
                $cotizacion->removeCotnota($this);
            }
        }
        return $this;
    }

    // alias por inflector inglés
    public function removeCotizacione(CotizacionCotizacion $cotizacion): self
    { return $this->removeCotizacion($cotizacion); }
}
