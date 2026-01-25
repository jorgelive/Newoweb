<?php

namespace App\Oweb\Entity;

use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * CotizacionCotpolitica
 */
#[ORM\Table(name: 'cot_cotpolitica')]
#[ORM\Entity]
#[Gedmo\TranslationEntity(class: 'App\Oweb\Entity\CotizacionCotpoliticaTranslation')]
class CotizacionCotpolitica
{
    #[ORM\Column(type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    /**
     * Relación con traducciones (mantener mapeo; el lado inverse mapea "object").
     * Nota: La clase *Translation* NO debe tipar $object ni su setter (regla Gedmo).
     */
    #[ORM\OneToMany(
        mappedBy: 'object',
        targetEntity: CotizacionCotpoliticaTranslation::class,
        cascade: ['persist', 'remove']
    )]
    protected Collection $translations;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $nombre = null;

    #[Gedmo\Translatable]
    #[ORM\Column(type: 'text')]
    private ?string $contenido = null;

    /**
     * Orphan removal se mantiene según tu preferencia.
     */
    #[ORM\OneToMany(
        mappedBy: 'cotpolitica',
        targetEntity: CotizacionCotizacion::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $cotizaciones;

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
        $this->cotizaciones  = new ArrayCollection();
        $this->translations  = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->getNombre() ?? sprintf('Id: %s.', (string) $this->getId()) ?? '';
    }

    public function setLocale(?string $locale): self
    {
        $this->locale = $locale;
        return $this;
    }

    /** @return Collection */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(CotizacionCotpoliticaTranslation $translation): self
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            // Mantener la sincronización del lado dueño según tu lógica actual
            $translation->setObject($this);
        }
        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setNombre(string $nombre): self
    {
        $this->nombre = $nombre;
        return $this;
    }

    public function getNombre(): ?string
    {
        return $this->nombre;
    }

    public function setCreado(DateTimeInterface $creado): self
    {
        $this->creado = $creado;
        return $this;
    }

    public function getCreado(): ?DateTimeInterface
    {
        return $this->creado;
    }

    public function setModificado(DateTimeInterface $modificado): self
    {
        $this->modificado = $modificado;
        return $this;
    }

    public function getModificado(): ?DateTimeInterface
    {
        return $this->modificado;
    }

    public function addCotizacion(CotizacionCotizacion $cotizacion): self
    {
        $cotizacion->setCotpolitica($this);
        $this->cotizaciones->add($cotizacion);
        return $this;
    }

    // Alias por inflector inglés (se mantiene igual)
    public function addCotizacione(CotizacionCotizacion $cotizacion): self
    {
        return $this->addCotizacion($cotizacion);
    }

    public function removeCotizacion(CotizacionCotizacion $cotizacion): void
    {
        // Se conserva tu lógica existente sin modificar el lado dueño
        $this->cotizaciones->removeElement($cotizacion);
    }

    // Alias por inflector inglés (se mantiene igual)
    public function removeCotizacione(CotizacionCotizacion $cotizacion): void
    {
        $this->removeCotizacion($cotizacion);
    }

    /** @return Collection */
    public function getCotizaciones(): Collection
    {
        return $this->cotizaciones;
    }

    public function setContenido(string $contenido): self
    {
        $this->contenido = $contenido;
        return $this;
    }

    public function getContenido(): ?string
    {
        return $this->contenido;
    }
}
