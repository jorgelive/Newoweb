<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
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

    private $path = '/carga/reservaunitmedio';

    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var ArrayCollection
     *
     * @ORM\OneToMany(targetEntity="App\Entity\ReservaUnitmedioTranslation", mappedBy="object", cascade={"persist", "remove"})
     */
    protected $translations;

    /**
     * @Gedmo\Translatable
     * @ORM\Column(type="string", length=255)
     */
    private $titulo;

    /**
     * @var \App\Entity\ReservaUnit
     *
     * @ORM\ManyToOne(targetEntity="ReservaUnit", inversedBy="unitmedios")
     * @ORM\JoinColumn(name="unit_id", referencedColumnName="id", nullable=false)
     */
    protected $unit;

    /**
     * @var \App\Entity\ReservaUnittipocaracteristica
     *
     * @ORM\ManyToOne(targetEntity="ReservaUnittipocaracteristica", inversedBy="unitmedios")
     * @ORM\JoinColumn(name="unittipocaracteristica_id", referencedColumnName="id", nullable=false)
     */
    protected $unittipocaracteristica;

    /**
     * @var \DateTime $creado
     *
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(type="datetime")
     */
    private $creado;

    /**
     * @var \DateTime $modificado
     * @Gedmo\Timestampable(on="update")
     * @ORM\Column(type="datetime")
     */
    private $modificado;

    /**
     * @Gedmo\Locale
     */
    private $locale;

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
        if(empty($this->getUnitclasemedio()) || empty($this->getNombre())){
            return sprintf("Id: %s.", $this->getId());
        }
        return sprintf('%s: %s', $this->getUnitclasemedio()->getNombre(), $this->getNombre());
    }


    public function getId(): int
    {
        return $this->id;
    }

    public function setUnit(?Reservaunit $unit):  self
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
