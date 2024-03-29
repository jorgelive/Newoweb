<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Translatable\Translatable;

use App\Traits\MainArchivoTrait;


/**
 * MaestroMedio
 *
 * @ORM\Table(name="res_unitmedio")
 * @ORM\Entity
 * @ORM\HasLifecycleCallbacks
 * @Gedmo\TranslationEntity(class="App\Entity\ReservaUnitmedioTranslation")
 */
class ReservaUnitmedio
{

    use MainArchivoTrait;

    private string $path = '/carga/reservaunitmedio';

    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private ?int $id = null;

    /**
     * @ORM\OneToMany(targetEntity="ReservaUnitmedioTranslation", mappedBy="object", cascade={"persist", "remove"})
     */
    protected Collection $translations;

    /**
     * @Gedmo\Translatable
     * @ORM\Column(type="string", length=255)
     */
    private ?string $titulo = null;

    /**
     * @ORM\ManyToOne(targetEntity="ReservaUnit", inversedBy="unitmedios")
     * @ORM\JoinColumn(name="unit_id", referencedColumnName="id", nullable=true)
     */
    protected ?ReservaUnit $unit;

    /**
     * @ORM\ManyToOne(targetEntity="ReservaUnittipocaracteristica", inversedBy="unitmedios")
     * @ORM\JoinColumn(name="unittipocaracteristica_id", referencedColumnName="id", nullable=true)
     */
    protected ?ReservaUnittipocaracteristica $unittipocaracteristica;

    /**
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(type="datetime")
     */
    private ?\DateTime $creado;

    /**
     * @Gedmo\Timestampable(on="update")
     * @ORM\Column(type="datetime")
     */
    private ?\DateTime $modificado;

    /**
     * @Gedmo\Locale
     */
    private ?string $locale = null;

    public function __construct()
    {
        $this->translations = new ArrayCollection();
    }

    public function setLocale(?string $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    public function getTranslations(): ?ArrayCollection
    {
        return $this->translations;
    }

    public function addTranslation(ReservaUnitmedioTranslation $translation)
    {
        if (!$this->translations->contains($translation)) {
            $this->translations[] = $translation;
            $translation->setObject($this);
        }
    }

    public function __toString(): string
    {
        if(empty($this->getUnittipocaracteristica()) || empty($this->getNombre())){
            return sprintf("Id: %s.", $this->getId());
        }
        return sprintf('%s: %s', $this->getUnittipocaracteristica()->getNombre(), $this->getNombre());
    }


    public function getId(): int
    {
        return $this->id;
    }

    public function setUnit(?ReservaUnit $unit):  self
    {
        $this->unit = $unit;

        return $this;
    }

    public function getUnit(): ?ReservaUnit
    {
        return $this->unit;
    }

    public function getUnittipocaracteristica(): ?ReservaUnittipocaracteristica
    {
        return $this->unittipocaracteristica;
    }

    public function setUnittipocaracteristica(?ReservaUnittipocaracteristica $unittipocaracteristica): self
    {
        $this->unittipocaracteristica = $unittipocaracteristica;

        return $this;
    }

    public function setCreado(?\DateTime $creado): self
    {
        $this->creado = $creado;

        return $this;
    }

    public function getCreado(): ?\DateTime
    {
        return $this->creado;
    }

    public function setModificado(?\DateTime $modificado): self
    {
        $this->modificado = $modificado;

        return $this;
    }

    public function getModificado(): ?\DateTime
    {
        return $this->modificado;
    }

    public function setTitulo(?string $titulo): self
    {
        $this->titulo = $titulo;
    
        return $this;
    }

    public function getTitulo(): ?string
    {
        return $this->titulo;
    }

}
