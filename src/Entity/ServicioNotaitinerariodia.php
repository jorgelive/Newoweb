<?php

namespace App\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\ArrayCollection;
use Gedmo\Translatable\Translatable;

/**
 * ServicioNotaitinerariodia
 *
 * @ORM\Table(name="ser_notaitinerariodia")
 * @ORM\Entity
 * @Gedmo\TranslationEntity(class="App\Entity\ServicioNotaitinerariodiaTranslation")
 */
class ServicioNotaitinerariodia
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
     * @ORM\OneToMany(targetEntity="ServicioNotaitinerariodiaTranslation", mappedBy="object", cascade={"persist", "remove"})
     */
    protected $translations;

    /**
     * @var string
     *
     * @ORM\Column(type="string", length=100)
     */
    private $nombre;

    /**
     * @var string
     * @Gedmo\Translatable
     * @ORM\Column(type="text")
     */
    private $contenido;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="ServicioItinerariodia", mappedBy="notaitinerariodia", cascade={"persist","remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"id" = "ASC"})
     */
    private $itinerariodias;

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

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->itinerariodias = new ArrayCollection();
        $this->translations = new ArrayCollection();
    }

    public function __clone() {
        if($this->id) {
            $this->id = null;
            $this->setCreado(null);
            $this->setModificado(null);
        }
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

    public function addTranslation(ServicioNotaitinerariodiaTranslation $translation)
    {
        if (!$this->translations->contains($translation)) {
            $this->translations[] = $translation;
            $translation->setObject($this);
        }
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
     * @return ServicioNotaitinerariodia
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
     * Set contenido
     *
     * @param string $contenido
     *
     * @return ServicioNotaitinerariodia
     */
    public function setContenido($contenido)
    {
        $this->contenido = $contenido;

        return $this;
    }

    /**
     * Get contenido
     *
     * @return string
     */
    public function getContenido()
    {
        return $this->contenido;
    }


    /**
     * Set creado
     *
     * @param \DateTime $creado
     *
     * @return ServicioNotaitinerariodia
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
     * @return ServicioNotaitinerariodia
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
     * Add itinerario
     *
     * @param \App\Entity\ServicioItinerario $itinerario
     *
     * @return ServicioNotaitinerariodia
     */
    public function addItinerariodia(\App\Entity\ServicioItinerariodia $itinerariodia)
    {
        $itinerariodia->setNotaitinerariodia($this);

        $this->itinerariodias[] = $itinerariodia;
    
        return $this;
    }

    /**
     * Remove itinerariodia
     *
     * @param \App\Entity\ServicioItinerariodia $itinerariodia
     */
    public function removeItinerariodia(\App\Entity\ServicioItinerario $itinerariodia)
    {
        $this->itinerariodias->removeElement($itinerariodia);
    }

    /**
     * Get itinerariodias
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getItinerariodias()
    {
        return $this->itinerariodias;
    }



}
