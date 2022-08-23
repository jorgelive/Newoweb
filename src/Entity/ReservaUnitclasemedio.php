<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Translatable\Translatable;

/**
 * ReservaUnitclasemedio
 *
 * @ORM\Table(name="res_unitclasemedio")
 * @ORM\Entity
 * @Gedmo\TranslationEntity(class="App\Entity\ReservaUnitclasemedioTranslation")
 */
class ReservaUnitclasemedio
{

    /**
     * @var int
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var string
     *
     * @ORM\Column(name="nombre", type="string", length=255)
     */
    private $nombre;

    /**
     * @var string
     *
     * @Gedmo\Translatable
     * @ORM\Column(name="titulo", type="string", length=100, nullable=false)
     */
    private $titulo;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="App\Entity\ReservaUnitmedio", mappedBy="unitclasemedio", cascade={"persist","remove"}, orphanRemoval=true)
     */
    private $unitmedios;

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
     * Used locale to override Translation listener`s locale
     * this is not a mapped field of entity metadata, just a simple property
     */
    private $locale;

    public function __construct()
    {
        $this->unitmedios = new ArrayCollection();
    }


    /**
     * @return string
     */
    public function __toString()
    {
        return $this->getNombre() ?? sprintf("Id: %s.", $this->getId()) ?? '';
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
     * Set nombre
     *
     * @param string $nombre
     *
     * @return ReservaUnitclasemedio
     */
    public function setNombre($nombre)
    {
        $this->nombre = $nombre;
    
        return $this;
    }

    /**
     * Get nombre
     *
     * @return string
     */
    public function getNombre()
    {
        return $this->nombre;
    }

    /**
     * Set creado
     *
     * @param \DateTime $creado
     *
     * @return ReservaUnitclasemedio
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
     *
     * @return ReservaUnitclasemedio
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
     * @param string|null $titulo
     *
     * @return ReservaUnitclasemedio
     */
    public function setTitulo($titulo = null)
    {
        $this->titulo = $titulo;
    
        return $this;
    }

    /**
     * Get titulo.
     *
     * @return string|null
     */
    public function getTitulo()
    {
        return $this->titulo;
    }

    /**
     * Add unitmedio.
     *
     * @param \App\Entity\ReservaUnitmedio $unitmedio
     *
     * @return ReservaUnitclasemedio
     */
    public function addUnitmedio(\App\Entity\ReservaUnitmedio $unitmedio)
    {
        $unitmedio->setUnitclasemedio($this);

        $this->unitmedios[] = $unitmedio;

        return $this;
    }

    /**
     * Remove unitmedio.
     *
     * @param \App\Entity\ReservaUnitmedio $unitmedio
     *
     * @return ReservaUnitclasemedio
     */
    public function removeUnitmedio(\App\Entity\Reservaunitmedio $unitmedio)
    {

        if($this->unitmedios->removeElement($unitmedio)) {
            // set the owning side to null (unless already changed)
            if($unitmedio->getUnitclasemedio() === $this) {
                $unitmedio->setUnitclasemedio(null);
            }
        }

        return $this;
    }

    /**
     * Get unitmedios.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getUnitmedios()
    {
        return $this->unitmedios;
    }


}
