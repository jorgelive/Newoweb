<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Translatable\Translatable;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * ReservaUnitcaracteristica
 *
 * @ORM\Table(name="res_unitcaracteristica")
 * @ORM\Entity
 * @Gedmo\TranslationEntity(class="App\Entity\ReservaUnitcaracteristicaTranslation")
 */
class ReservaUnitcaracteristica
{

    /**
     * @var int
     *
     * @ORM\Column(type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var ArrayCollection
     *
     * @ORM\OneToMany(targetEntity="App\Entity\ReservaUnitcaracteristicaTranslation", mappedBy="object", cascade={"persist", "remove"})
     */
    protected $translations;

    /**
     * @var string
     *
     * @Gedmo\Translatable
     * @ORM\Column(type="text")
     */
    private $contenido;

    /**
     * @var string
     *
     * @ORM\Column(type="text", columnDefinition= "longtext AS (contenido) VIRTUAL NULL", generated="ALWAYS", insertable=false, updatable=false )
     */
    private $contenidooriginal;

    /**
     * @var \App\Entity\ReservaUnittipocaracteristica
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\ReservaUnittipocaracteristica", inversedBy="unitcaracteristicas")
     * @ORM\JoinColumn(name="unittipocaracteristica_id", referencedColumnName="id", nullable=false)
     */
    protected $unittipocaracteristica;

    /**
     * @var int
     *
     * @ORM\Column(name="prioridad", type="integer", nullable=true)
     */
    private $prioridad;

    /**
     * @var \App\Entity\ReservaUnit
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\ReservaUnit", inversedBy="unitcaracteristicas")
     * @ORM\JoinColumn(name="unit_id", referencedColumnName="id", nullable=false)
     */
    protected $unit;

    /**
     * @var \DateTime $creado
     *
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(type="datetime")
     */
    private $creado;

    /**
     * @var \DateTime $modificado
     *
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

    /**
     * @return string
     */
    public function __toString()
    {
        return substr(str_replace("&nbsp;", '', strip_tags($this->getContenido())), 0, 100) . '...';
    }

    public function setLocale(?string $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    public function getTranslations()
    {
        return $this->translations;
    }

    public function addTranslation(ReservaUnitcaracteristicaTranslation $translation)
    {
        if (!$this->translations->contains($translation)) {
            $this->translations[] = $translation;
            $translation->setObject($this);
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContenido(): ?string
    {
        return $this->contenido;
    }

    public function setContenido(string $contenido): self
    {
        $this->contenido = $contenido;

        return $this;
    }

    public function getContenidooriginal(): ?string
    {
        return $this->contenidooriginal;
    }

    public function getCreado(): ?\DateTimeInterface
    {
        return $this->creado;
    }

    public function setCreado(\DateTimeInterface $creado): self
    {
        $this->creado = $creado;

        return $this;
    }

    public function getModificado(): ?\DateTimeInterface
    {
        return $this->modificado;
    }

    public function setModificado(\DateTimeInterface $modificado): self
    {
        $this->modificado = $modificado;

        return $this;
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

    public function setPrioridad(?int $prioridad): self
    {
        $this->prioridad = $prioridad;

        return $this;
    }

    public function getPrioridad(): ?int
    {
        return $this->prioridad;
    }

    public function getUnit(): ?ReservaUnit
    {
        return $this->unit;
    }

    public function setUnit(?ReservaUnit $unit): self
    {
        $this->unit = $unit;

        return $this;
    }


}