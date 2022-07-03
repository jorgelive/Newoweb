<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

use App\Traits\MainArchivoTrait;


/**
 * ServicioItidiaarchivo
 *
 * @ORM\Table(name="ser_itidiaarchivo")
 * @ORM\Entity
 * @ORM\HasLifecycleCallbacks
 */
class ServicioItidiaarchivo
{

    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var \App\Entity\ServicioItinerariodia
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\ServicioItinerariodia", inversedBy="itidiaarchivos")
     * @ORM\JoinColumn(name="itinerariodia_id", referencedColumnName="id", nullable=false)
     */
    protected $itinerariodia;

    /**
     * @var \App\Entity\MaestroMedio
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\MaestroMedio")
     * @ORM\JoinColumn(name="medio_id", referencedColumnName="id", nullable=false)
     */
    private $medio;

    /**
     * @var int
     *
     * @ORM\Column(name="prioridad", type="integer", nullable=true)
     */
    private $prioridad;

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
     * @return string
     */
    public function __toString()
    {
        return $this->getMedio()->getNombre() ?? sprintf("Id: %s.", $this->getId()) ?? '';
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
     * Set itinerariodia
     *
     * @param \App\Entity\ServicioItinerariodia $itinerariodia
     *
     * @return ServicioItidiaarchivo
     */
    public function setItinerariodia(\App\Entity\ServicioItinerariodia $itinerariodia = null)
    {
        $this->itinerariodia = $itinerariodia;

        return $this;
    }

    /**
     * Get itinerariodia
     *
     * @return \App\Entity\ServicioItinerariodia
     */
    public function getItinerariodia()
    {
        return $this->itinerariodia;
    }

    /**
     * Set creado
     *
     * @param \DateTime $creado
     * @return ServicioItidiaarchivo
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
     * @return ServicioItidiaarchivo
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
     * Set prioridad.
     *
     * @param int|null $prioridad
     *
     * @return ServicioItidiaarchivo
     */
    public function setPrioridad($prioridad = null)
    {
        $this->prioridad = $prioridad;
    
        return $this;
    }

    /**
     * Get prioridad.
     *
     * @return int|null
     */
    public function getPrioridad()
    {
        return $this->prioridad;
    }

    /**
     * Set medio.
     *
     * @param \App\Entity\MaestroMedio|null $medio
     *
     * @return ServicioItidiaarchivo
     */
    public function setMedio(\App\Entity\MaestroMedio $medio = null)
    {
        $this->medio = $medio;
    
        return $this;
    }

    /**
     * Get medio.
     *
     * @return \App\Entity\MaestroMedio|null
     */
    public function getMedio()
    {
        return $this->medio;
    }
}
