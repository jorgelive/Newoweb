<?php

namespace App\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Translatable\Translatable;
use Doctrine\Common\Collections\ArrayCollection;


/**
 * ServicioItinerariodia
 *
 * @ORM\Table(name="ser_itinerariodia")
 * @ORM\Entity
 * @Gedmo\TranslationEntity(class="App\Entity\ServicioItinerariodiaTranslation")
 */
class ServicioItinerariodia implements Translatable
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
     * @var \App\Entity\ServicioItinerario
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\ServicioItinerario", inversedBy="itinerariodias")
     * @ORM\JoinColumn(name="itinerario_id", referencedColumnName="id", nullable=false)
     */
    protected $itinerario;

    /**
     * @var int
     *
     * @ORM\Column(type="integer")
     */
    private $dia = 1;

    /**
     * @var string
     *
     * @Gedmo\Translatable
     * @ORM\Column(type="string", length=100)
     */
    private $titulo;

    /**
     * @var bool
     *
     * @ORM\Column(type="boolean", options={"default": 1})
     */
    private $importante;

    /**
     * @var string
     *
     * @Gedmo\Translatable
     * @ORM\Column(type="text")
     */
    private $contenido;

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
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="App\Entity\ServicioItidiaarchivo", mappedBy="itinerariodia", cascade={"persist","remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"prioridad" = "ASC"})
     */
    private $itidiaarchivos;

    /**
     * @var \App\Entity\ServicioNotaitinerariodia
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\ServicioNotaitinerariodia", inversedBy="itinerariodias")
     * @ORM\JoinColumn(name="notaitinerariodia_id", referencedColumnName="id", nullable=true)
     */
    protected $notaitinerariodia;

    /**
     * @Gedmo\Locale
     * Used locale to override Translation listener`s locale
     * this is not a mapped field of entity metadata, just a simple property
     */
    private $locale;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->itidiaarchivos = new ArrayCollection();
    }

    public function __clone() {
        if($this->id) {
            $this->id = null;
            $this->setCreado(null);
            $this->setModificado(null);

            $newItidiaarchivos = new ArrayCollection();
            foreach($this->itidiaarchivos as $itidiaarchivo) {
                $newItidiaarchivo = clone $itidiaarchivo;
                $newItidiaarchivo->setItinerariodia($this);
                $newItidiaarchivos->add($newItidiaarchivo);
            }

            $this->itidiaarchivos = $newItidiaarchivos;
        }
    }


    /**
     * @return string
     */
    public function __toString()
    {
        return sprintf('Dia %d: %s', $this->getDia(), $this->getItinerario()->getNombre());
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
     * Set dia
     *
     * @param integer $dia
     *
     * @return ServicioItinerariodia
     */
    public function setDia($dia)
    {
        $this->dia = $dia;
    
        return $this;
    }

    /**
     * Get dia
     *
     * @return integer
     */
    public function getDia()
    {
        return $this->dia;
    }

    /**
     * Set titulo
     *
     * @param string $titulo
     *
     * @return ServicioItinerariodia
     */
    public function setTitulo($titulo)
    {
        $this->titulo = $titulo;
    
        return $this;
    }

    /**
     * Get titulo
     *
     * @return string
     */
    public function getTitulo()
    {
        return $this->titulo;
    }

    /**
     * Set contenido
     *
     * @param string $contenido
     *
     * @return ServicioItinerariodia
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
     * @return ServicioItinerariodia
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
     * @return ServicioItinerariodia
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
     * Set itinerario
     *
     * @param \App\Entity\ServicioItinerario $itinerario
     *
     * @return ServicioItinerariodia
     */
    public function setItinerario(\App\Entity\ServicioItinerario $itinerario = null)
    {
        $this->itinerario = $itinerario;
    
        return $this;
    }

    /**
     * Get itinerario
     *
     * @return \App\Entity\ServicioItinerario
     */
    public function getItinerario()
    {
        return $this->itinerario;
    }


    /**
     * Add itidiaarchivo.
     *
     * @param \App\Entity\ServicioItidiaarchivo $itidiaarchivo
     *
     * @return ServicioItinerariodia
     */
    public function addItidiaarchivo(\App\Entity\ServicioItidiaarchivo $itidiaarchivo)
    {
        $itidiaarchivo->setItinerariodia($this);

        $this->itidiaarchivos[] = $itidiaarchivo;
    
        return $this;
    }

    /**
     * Remove itidiaarchivo.
     *
     * @param \App\Entity\ServicioItidiaarchivo $itidiaarchivo
     *
     * @return boolean TRUE if this collection contained the specified element, FALSE otherwise.
     */
    public function removeItidiaarchivo(\App\Entity\ServicioItidiaarchivo $itidiaarchivo)
    {
        return $this->itidiaarchivos->removeElement($itidiaarchivo);
    }

    /**
     * Get itidiaarchivos.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getItidiaarchivos()
    {
        return $this->itidiaarchivos;
    }

    /**
     * Set notaitinerariodia
     *
     * @param \App\Entity\ServicioNotaitinerariodia $notaitinerariodia
     *
     * @return ServicioItinerariodia
     */
    public function setNotaitinerariodia(\App\Entity\ServicioNotaitinerariodia $notaitinerariodia = null)
    {
        $this->notaitinerariodia = $notaitinerariodia;

        return $this;
    }

    /**
     * Get notaitinerariodia
     *
     * @return \App\Entity\ServicioNotaitinerariodia
     */
    public function getNotaitinerariodia()
    {
        return $this->notaitinerariodia;
    }


    /**
     * Set importante.
     *
     * @param bool $importante
     *
     * @return ServicioItinerariodia
     */
    public function setImportante($importante)
    {
        $this->importante = $importante;
    
        return $this;
    }

    /**
     * Is importante.
     *
     * @return bool
     */
    public function isImportante(): ?bool
    {
        return $this->importante;
    }
}
