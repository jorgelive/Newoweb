<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

/**
 * @ORM\Table(
 *   name="res_unitcaracteristica",
 *   indexes={
 *     @ORM\Index(name="idx_unitcarac_nombre", columns={"nombre"})
 *   }
 * )
 * @ORM\Entity
 * @Gedmo\TranslationEntity(class="App\Entity\ReservaUnitcaracteristicaTranslation")
 */
class ReservaUnitcaracteristica
{
    /**
     * @ORM\Column(type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private ?int $id = null;

    /**
     * Nombre interno para identificar rápidamente la característica
     * (no translatable, uso backoffice)
     *
     * @ORM\Column(type="string", length=191, nullable=false)
     */
    private string $nombre = '';

    /**
     * @ORM\OneToMany(
     *     targetEntity="App\Entity\ReservaUnitcaracteristicaTranslation",
     *     mappedBy="object",
     *     cascade={"persist", "remove"}
     * )
     */
    protected Collection $translations;

    /**
     * @Gedmo\Translatable
     * @ORM\Column(type="text")
     */
    private ?string $contenido = null;

    /**
     * Campo virtual que refleja el contenido original (sin traducción)
     *
     * @ORM\Column(
     *   type="text",
     *   columnDefinition="LONGTEXT GENERATED ALWAYS AS (`contenido`) VIRTUAL",
     *   nullable=true,
     *   insertable=false,
     *   updatable=false
     * )
     */
    private ?string $contenidooriginal = null;

    /**
     * Tipo de característica
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\ReservaUnittipocaracteristica", inversedBy="unitcaracteristicas")
     * @ORM\JoinColumn(name="unittipocaracteristica_id", referencedColumnName="id", nullable=false)
     */
    protected ?ReservaUnittipocaracteristica $unittipocaracteristica = null;

    /**
     * Medios hijos de esta característica
     *
     * @ORM\OneToMany(
     *     targetEntity="App\Entity\ReservaUnitmedio",
     *     mappedBy="unitcaracteristica",
     *     cascade={"persist","remove"},
     *     orphanRemoval=true
     * )
     * @ORM\OrderBy({"prioridad" = "ASC"})
     */
    private Collection $medios;

    /**
     * Vínculos con Unidades (lado inverso del link)
     *
     * @ORM\OneToMany(
     *     targetEntity="App\Entity\ReservaUnitCaracteristicaLink",
     *     mappedBy="caracteristica",
     *     cascade={"persist","remove"},
     *     orphanRemoval=true
     * )
     * @ORM\OrderBy({"prioridad" = "ASC"})
     */
    private Collection $links;

    /**
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(type="datetime")
     */
    private ?\DateTimeInterface $creado = null;

    /**
     * @Gedmo\Timestampable(on="update")
     * @ORM\Column(type="datetime")
     */
    private ?\DateTimeInterface $modificado = null;

    /**
     * @Gedmo\Locale
     */
    private ?string $locale = null;

    public function __construct()
    {
        $this->translations = new ArrayCollection();
        $this->medios = new ArrayCollection();
        $this->links = new ArrayCollection(); // 👈 inicializar
    }

    public function __toString(): string
    {
        if ($this->getNombre()) {
            return (string) $this->getNombre();
        }
        return substr(str_replace("&nbsp;", '', strip_tags((string) $this->getContenido())), 0, 100) . '...';
    }

    public function setLocale(?string $locale): self { $this->locale = $locale; return $this; }

    /** @return Collection|ReservaUnitcaracteristicaTranslation[] */
    public function getTranslations(): Collection { return $this->translations; }

    public function addTranslation(ReservaUnitcaracteristicaTranslation $translation): self
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setObject($this);
        }
        return $this;
    }

    public function removeTranslation(ReservaUnitcaracteristicaTranslation $translation): self
    {
        $this->translations->removeElement($translation);
        return $this;
    }

    public function getId(): ?int { return $this->id; }

    public function getNombre(): string { return $this->nombre; }
    public function setNombre(?string $nombre): self { $this->nombre = (string)($nombre ?? ''); return $this; }

    public function getContenido(): ?string { return $this->contenido; }
    public function setContenido(?string $contenido): self { $this->contenido = $contenido; return $this; }

    public function getContenidooriginal(): ?string { return $this->contenidooriginal; }

    public function getUnittipocaracteristica(): ?ReservaUnittipocaracteristica { return $this->unittipocaracteristica; }
    public function setUnittipocaracteristica(?ReservaUnittipocaracteristica $tipo): self
    {
        $this->unittipocaracteristica = $tipo;
        return $this;
    }

    public function getCreado(): ?\DateTimeInterface { return $this->creado; }
    public function setCreado(\DateTimeInterface $creado): self { $this->creado = $creado; return $this; }

    public function getModificado(): ?\DateTimeInterface { return $this->modificado; }
    public function setModificado(\DateTimeInterface $modificado): self { $this->modificado = $modificado; return $this; }

    /** @return Collection|ReservaUnitmedio[] */
    public function getMedios(): Collection { return $this->medios; }

    public function addMedio(ReservaUnitmedio $medio): self
    {
        if (!$this->medios->contains($medio)) {
            $this->medios->add($medio);
            $medio->setUnitcaracteristica($this);
        }
        return $this;
    }

    public function removeMedio(ReservaUnitmedio $medio): self
    {
        if ($this->medios->removeElement($medio)) {
            if ($medio->getUnitcaracteristica() === $this) {
                $medio->setUnitcaracteristica(null);
            }
        }
        return $this;
    }

    /** ================== LINKS (vínculos con Unidades) ================== */

    /** @return Collection|ReservaUnitCaracteristicaLink[] */
    public function getLinks(): Collection { return $this->links; }

    public function addLink(ReservaUnitCaracteristicaLink $link): self
    {
        if (!$this->links->contains($link)) {
            $this->links->add($link);
            $link->setCaracteristica($this);
        }
        return $this;
    }

    public function removeLink(ReservaUnitCaracteristicaLink $link): self
    {
        if ($this->links->removeElement($link)) {
            if ($link->getCaracteristica() === $this) {
                $link->setCaracteristica(null);
            }
        }
        return $this;
    }
}
