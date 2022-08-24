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
     * @var \App\Entity\ReservaUnitclasemedio
     *
     * @ORM\ManyToOne(targetEntity="ReservaUnitclasemedio", inversedBy="unitmedios")
     * @ORM\JoinColumn(name="unitclasemedio_id", referencedColumnName="id", nullable=false)
     */
    protected $unitclasemedio;

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

    /**
     * @return string
     */
    public function __toString()
    {
        if(empty($this->getUnitclasemedio()) || empty($this->getNombre())){
            return sprintf("Id: %s.", $this->getId());
        }
        return sprintf('%s: %s', $this->getUnitclasemedio()->getNombre(), $this->getNombre());
    }


    /**
     * Get id
     *
     * @return integer
     */
    public function getId()
    {
        return $this->id;
    }


    /**
     * Set unit
     *
     * @param \App\Entity\Reservaunit $unit
     *
     * @return ReservaUnitmedio
     */
    public function setUnit(\App\Entity\Reservaunit $unit)
    {
        $this->unit = $unit;

        return $this;
    }

    /**
     * Get unit
     *
     * @return \App\Entity\ReservaUnit
     */
    public function getUnit()
    {
        return $this->unit;
    }

    /**
     * Set unitclasemedio
     *
     * @param \App\Entity\Reservaunitclasemedio $unitclasemedio
     *
     * @return ReservaUnitmedio
     */
    public function setUnitclasemedio(\App\Entity\Reservaunitclasemedio $unitclasemedio = null)
    {
        $this->unitclasemedio = $unitclasemedio;

        return $this;
    }

    /**
     * Get unitclasemedio
     *
     * @return \App\Entity\ReservaUnitclasemedio
     */
    public function getUnitclasemedio()
    {
        return $this->unitclasemedio;
    }


    /**
     * Set creado
     *
     * @param \DateTime $creado
     * @return ReservaUnitmedio
     */
    public function setCreado($creado)
    {
        $this->creado = $creado;

        return $this;
    }

    /**
     * Get creado
     *
     * @return \DateTime
     */
    public function getCreado()
    {
        return $this->creado;
    }

    /**
     * Set modificado
     *
     * @param \DateTime $modificado
     * @return ReservaUnitmedio
     */
    public function setModificado($modificado)
    {
        $this->modificado = $modificado;

        return $this;
    }

    /**
     * Get modificado
     *
     * @return \DateTime
     */
    public function getModificado()
    {
        return $this->modificado;
    }

    /**
     * Set titulo.
     *
     * @param string $titulo
     *
     * @return ReservaUnitmedio
     */
    public function setTitulo($titulo)
    {
        $this->titulo = $titulo;
    
        return $this;
    }

    /**
     * Get titulo.
     *
     * @return string
     */
    public function getTitulo()
    {
        return $this->titulo;
    }

}
