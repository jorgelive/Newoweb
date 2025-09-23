<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Gedmo\Translatable\Translatable;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * CotizacionCotnota
 *
 * @ORM\Table(name="cot_cotnota")
 * @ORM\Entity
 * @Gedmo\TranslationEntity(class="App\Entity\CotizacionCotnotaTranslation")
 */
class CotizacionCotnota implements Translatable
{
    /** @ORM\Id @ORM\GeneratedValue(strategy="AUTO") @ORM\Column(type="integer") */
    private ?int $id = null;

    /** @ORM\Column(name="nombre", type="string", length=255) */
    private ?string $nombre = null;

    /**
     * @Gedmo\Translatable
     * @ORM\Column(name="titulo", type="string", length=100, nullable=true)
     */
    private ?string $titulo = null;

    /**
     * @Gedmo\Translatable
     * @ORM\Column(name="contenido", type="text", nullable=true)
     */
    private ?string $contenido = null;

    /**
     * Lado inverso (si el propietario es CotizacionCotizacion.cotnotas)
     * IMPORTANTE: no declarar @ JoinTable aquí si usas mappedBy.
     *
     * @ORM\ManyToMany(targetEntity="App\Entity\CotizacionCotizacion", mappedBy="cotnotas", cascade={"persist","remove"})
     * @var Collection<int,CotizacionCotizacion>
     */
    private Collection $cotizaciones;

    /**
     * Relación inversa a las traducciones (Gedmo PersonalTranslation)
     *
     * @ORM\OneToMany(
     *     targetEntity="App\Entity\CotizacionCotnotaTranslation",
     *     mappedBy="object",
     *     cascade={"persist","remove"},
     *     orphanRemoval=true
     * )
     * @var Collection<int,\App\Entity\CotizacionCotnotaTranslation>
     */
    private Collection $translations;

    /**
     * Marcar como nullable para evitar warnings hasta la primera persistencia
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(type="datetime", nullable=false)
     */
    private ?\DateTimeInterface $creado = null;

    /**
     * Marcar como nullable para evitar warnings hasta la primera actualización
     * @Gedmo\Timestampable(on="update")
     * @ORM\Column(type="datetime", nullable=false)
     */
    private ?\DateTimeInterface $modificado = null;

    /**
     * @Gedmo\Locale
     */
    private ?string $locale = null;

    public function __construct()
    {
        $this->cotizaciones = new ArrayCollection();
        $this->translations = new ArrayCollection();
    }

    // ----------------------
    // Translatable helpers
    // ----------------------

    public function setLocale(?string $locale): self
    {
        $this->locale = $locale;
        return $this;
    }

    /** @return Collection<int,\App\Entity\CotizacionCotnotaTranslation> */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(CotizacionCotnotaTranslation $translation): self
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setObject($this);
        }
        return $this;
    }

    public function removeTranslation(CotizacionCotnotaTranslation $translation): self
    {
        if ($this->translations->removeElement($translation)) {
            if ($translation->getObject() === $this) {
                $translation->setObject(null);
            }
        }
        return $this;
    }

    // ----------------------
    // Magic & basics
    // ----------------------

    public function __toString(): string
    {
        return $this->getNombre() ?? sprintf('Id: %s.', $this->getId()) ?? '';
    }

    // ----------------------
    // Getters / Setters
    // ----------------------

    public function getId(): ?int { return $this->id; }

    public function setNombre(string $nombre): self
    {
        $this->nombre = $nombre;
        return $this;
    }

    public function getNombre(): ?string { return $this->nombre; }

    public function setTitulo(?string $titulo): self
    {
        $this->titulo = $titulo;
        return $this;
    }

    public function getTitulo(): ?string { return $this->titulo; }

    public function setContenido(?string $contenido): self
    {
        $this->contenido = $contenido;
        return $this;
    }

    public function getContenido(): ?string { return $this->contenido; }

    public function getCreado(): ?\DateTimeInterface { return $this->creado; }
    public function setCreado(?\DateTimeInterface $creado): self { $this->creado = $creado; return $this; }

    public function getModificado(): ?\DateTimeInterface { return $this->modificado; }
    public function setModificado(?\DateTimeInterface $modificado): self { $this->modificado = $modificado; return $this; }

    /** @return Collection<int,CotizacionCotizacion> */
    public function getCotizaciones(): Collection
    {
        return $this->cotizaciones;
    }

    public function addCotizacion(CotizacionCotizacion $cotizacion): self
    {
        if (!$this->cotizaciones->contains($cotizacion)) {
            $this->cotizaciones->add($cotizacion);
            // sincroniza el otro lado si existe el método
            if (method_exists($cotizacion, 'addCotnota')) {
                $cotizacion->addCotnota($this);
            }
        }
        return $this;
    }

    // alias por tu inflector inglés
    public function addCotizacione(CotizacionCotizacion $cotizacion): self
    {
        return $this->addCotizacion($cotizacion);
    }

    public function removeCotizacion(CotizacionCotizacion $cotizacion): self
    {
        if ($this->cotizaciones->removeElement($cotizacion)) {
            if (method_exists($cotizacion, 'removeCotnota')) {
                $cotizacion->removeCotnota($this);
            }
        }
        return $this;
    }

    // alias por tu inflector inglés
    public function removeCotizacione(CotizacionCotizacion $cotizacion): self
    {
        return $this->removeCotizacion($cotizacion);
    }
}
