<?php

namespace App\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Translatable\Translatable;

/**
 * ReservaEstablecimiento
 *
 * @ORM\Table(name="res_establecimiento")
 * @ORM\Entity
 * @Gedmo\TranslationEntity(class="App\Entity\ReservaEstablecimientoTranslation")
 */
class ReservaEstablecimiento implements Translatable
{
    /**
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private ?int $id = null;

    /** @ORM\Column(type="string", length=255) */
    private ?string $nombre = null;

    /** @ORM\Column(type="string", length=255) */
    private ?string $direccion = null;

    /**
     * Campo traducible
     * @Gedmo\Translatable
     * @ORM\Column(type="string", length=255)
     */
    private ?string $referencia = null;

    /** @ORM\Column(type="string", length=5) */
    private ?string $checkin = null;

    /** @ORM\Column(type="string", length=5) */
    private ?string $checkout = null;

    /**
     * @ORM\OneToMany(targetEntity="ReservaUnit", mappedBy="establecimiento", cascade={"persist","remove"}, orphanRemoval=true)
     * @var Collection<int, ReservaUnit>
     */
    private Collection $units;

    /**
     * Lado inverso de las traducciones (Gedmo PersonalTranslation)
     *
     * @ORM\OneToMany(
     *     targetEntity="App\Entity\ReservaEstablecimientoTranslation",
     *     mappedBy="object",
     *     cascade={"persist","remove"},
     *     orphanRemoval=true
     * )
     * @var Collection<int, \App\Entity\ReservaEstablecimientoTranslation>
     */
    private Collection $translations;

    /**
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(type="datetime", nullable=false)
     */
    private ?\DateTimeInterface $creado = null;

    /**
     * @Gedmo\Timestampable(on="update")
     * @ORM\Column(type="datetime", nullable=false)
     */
    private ?\DateTimeInterface $modificado = null;

    /**
     * @Gedmo\Locale
     */
    private ?string $locale = null;

    public function __construct() {
        $this->units = new ArrayCollection();
        $this->translations = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->getNombre() ?? sprintf("Id: %s.", $this->getId()) ?? '';
    }

    // ------- Translatable helpers -------
    public function setLocale(?string $locale): self
    {
        $this->locale = $locale;
        return $this;
    }

    /** @return Collection<int,\App\Entity\ReservaEstablecimientoTranslation> */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(ReservaEstablecimientoTranslation $translation): self
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setObject($this);
        }
        return $this;
    }

    public function removeTranslation(ReservaEstablecimientoTranslation $translation): self
    {
        if ($this->translations->removeElement($translation)) {
            if ($translation->getObject() === $this) {
                $translation->setObject(null);
            }
        }
        return $this;
    }

    // ------- Getters / Setters básicos -------
    public function getId(): ?int { return $this->id; }

    public function getNombre(): ?string { return $this->nombre; }
    public function setNombre(string $nombre): self { $this->nombre = $nombre; return $this; }

    public function getDireccion(): ?string { return $this->direccion; }
    public function setDireccion(string $direccion): self { $this->direccion = $direccion; return $this; }

    public function getReferencia(): ?string { return $this->referencia; }
    public function setReferencia(string $referencia): self { $this->referencia = $referencia; return $this; }

    public function getCheckin(): ?string { return $this->checkin; }
    public function setCheckin(string $checkin): self { $this->checkin = $checkin; return $this; }

    public function getCheckout(): ?string { return $this->checkout; }
    public function setCheckout(string $checkout): self { $this->checkout = $checkout; return $this; }

    public function getCreado(): ?\DateTimeInterface { return $this->creado; }
    public function setCreado(?\DateTimeInterface $creado): self { $this->creado = $creado; return $this; }

    public function getModificado(): ?\DateTimeInterface { return $this->modificado; }
    public function setModificado(?\DateTimeInterface $modificado): self { $this->modificado = $modificado; return $this; }

    /** @return Collection<int, ReservaUnit> */
    public function getUnits(): Collection { return $this->units; }

    public function addUnit(ReservaUnit $unit): self
    {
        if(!$this->units->contains($unit)) {
            $this->units->add($unit);
            $unit->setEstablecimiento($this);
        }
        return $this;
    }

    public function removeUnit(ReservaUnit $unit): self
    {
        if($this->units->removeElement($unit)) {
            if($unit->getEstablecimiento() === $this) {
                $unit->setEstablecimiento(null);
            }
        }
        return $this;
    }
}
