<?php

namespace App\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Translatable\Translatable;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * ServicioItinerario
 *
 * @ORM\Table(name="ser_itinerario")
 * @ORM\Entity
 * @Gedmo\TranslationEntity(class="App\Entity\ServicioItinerarioTranslation")
 */
class ServicioItinerario implements Translatable
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
     * @ORM\Column(name="nombre", type="string", length=100)
     */
    private $nombre;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="hora", type="time")
     */
    private $hora;

    /**
     * @var string
     *
     * @ORM\Column(name="duracion", type="decimal", precision=4, scale=1)
     */
    private $duracion;

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
     * @var \App\Entity\ServicioServicio
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\ServicioServicio", inversedBy="itinerarios")
     * @ORM\JoinColumn(name="servicio_id", referencedColumnName="id", nullable=false)
     */
    protected $servicio;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="App\Entity\ServicioItinerariodia", mappedBy="itinerario", cascade={"persist","remove"}, orphanRemoval=true)
     */
    private $itinerariodias;

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
        $this->itinerariodias = new ArrayCollection();
    }

    public function __clone() {
        if ($this->id) {
            $this->id = null;
            $this->setCreado(null);
            $this->setModificado(null);

            $newItinerariodias = new ArrayCollection();
            foreach ($this->itinerariodias as $itinerariodia) {
                $newItinerariodia = clone $itinerariodia;
                $newItinerariodia->setItinerario($this);
                $newItinerariodias->add($newItinerariodia);
            }
            $this->itinerariodias = $newItinerariodias;

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
     * @return ServicioItinerario
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
     * @return ServicioItinerario
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
     * @return ServicioItinerario
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
     * Set servicio
     *
     * @param \App\Entity\ServicioServicio $servicio
     *
     * @return ServicioItinerario
     */
    public function setServicio(\App\Entity\ServicioServicio $servicio = null)
    {
        $this->servicio = $servicio;
    
        return $this;
    }

    /**
     * Get servicio
     *
     * @return \App\Entity\ServicioServicio
     */
    public function getServicio()
    {
        return $this->servicio;
    }

    /**
     * Add itinerariodia
     *
     * @param \App\Entity\ServicioItinerariodia $itinerariodia
     *
     * @return ServicioItinerario
     */
    public function addItinerariodia(\App\Entity\ServicioItinerariodia $itinerariodia)
    {
        $itinerariodia->setItinerario($this);

        $this->itinerariodias[] = $itinerariodia;
    
        return $this;
    }

    /**
     * Remove itinerariodia
     *
     * @param \App\Entity\ServicioItinerariodia $itinerariodia
     */
    public function removeItinerariodia(\App\Entity\ServicioItinerariodia $itinerariodia)
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

    /**
     * Set hora
     *
     * @param \DateTime $hora
     *
     * @return ServicioItinerario
     */
    public function setHora($hora)
    {
        $this->hora = $hora;
    
        return $this;
    }

    /**
     * Get hora
     *
     * @return \DateTime
     */
    public function getHora()
    {
        return $this->hora;
    }

    /**
     * Set duracion
     *
     * @param string $duracion
     *
     * @return ServicioItinerario
     */
    public function setDuracion($duracion)
    {
        $this->duracion = $duracion;
    
        return $this;
    }

    /**
     * Get duracion
     *
     * @return string
     */
    public function getDuracion()
    {
        return $this->duracion;
    }

}
