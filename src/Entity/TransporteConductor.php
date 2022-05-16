<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;


/**
 * @ORM\Table(name="tra_conductor")
 * @ORM\Entity
 */
class TransporteConductor
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=15)
     */
    private $licencia;

    /**
     * @ORM\Column(type="string", length=5)
     */
    private $abreviatura;

    /**
     * @ORM\Column(type="string", length=10)
     */
    private $color;

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
     * @ORM\OneToMany(targetEntity="TransporteServicio", mappedBy="conductor", cascade={"persist", "remove"}, orphanRemoval=true)
     */
    private $servicios;

    /**
     * @var \App\Entity\UserUser
     *
     * @ORM\OneToOne(targetEntity="App\Entity\UserUser", inversedBy="conductor")
     * @ORM\JoinColumn(name="user_id", referencedColumnName="id", nullable=false)
     */
    private $user;

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->getNombre();
    }

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->servicios = new \Doctrine\Common\Collections\ArrayCollection();
    }

    /**
     * Get nombre
     *
     * @return string
     */
    public function getNombre(){

        if(is_null($this->getUser()) || is_null($this->getUser()->getFullname())) {
            return sprintf("Id: %s.", $this->getId());
        }

        return sprintf("%s", $this->getUser()->getFullname());

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
     * Set licencia
     *
     * @param string $licencia
     *
     * @return TransporteConductor
     */
    public function setLicencia($licencia)
    {
        $this->licencia = $licencia;

        return $this;
    }

    /**
     * Get licencia
     *
     * @return string
     */
    public function getLicencia()
    {
        return $this->licencia;
    }

    /**
     * Set creado
     *
     * @param \DateTime $creado
     *
     * @return TransporteConductor
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
     * @return TransporteConductor
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
     * Add servicio
     *
     * @param \App\Entity\TransporteServicio $servicio
     *
     * @return TransporteConductor
     */
    public function addServicio(\App\Entity\TransporteServicio $servicio)
    {
        $servicio->setConductor($this);

        $this->servicios[] = $servicio;

        return $this;
    }

    /**
     * Remove servicio
     *
     * @param \App\Entity\TransporteServicio $servicio
     */
    public function removeServicio(\App\Entity\TransporteServicio $servicio)
    {
        $this->servicios->removeElement($servicio);
    }

    /**
     * Get servicios
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getServicios()
    {
        return $this->servicios;
    }

    /**
     * Set abreviatura
     *
     * @param string $abreviatura
     *
     * @return TransporteConductor
     */
    public function setAbreviatura($abreviatura)
    {
        $this->abreviatura = $abreviatura;

        return $this;
    }

    /**
     * Get abreviatura
     *
     * @return string
     */
    public function getAbreviatura()
    {
        return $this->abreviatura;
    }

    /**
     * Set user
     *
     * @param \App\Entity\UserUser $user
     *
     * @return TransporteConductor
     */
    public function setUser(\App\Entity\UserUser $user = null)
    {
        $this->user = $user;
    
        return $this;
    }

    /**
     * Get user
     *
     * @return \App\Entity\UserUser
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * Set color
     *
     * @param string $color
     *
     * @return TransporteConductor
     */
    public function setColor($color)
    {
        $this->color = $color;
    
        return $this;
    }

    /**
     * Get color
     *
     * @return string
     */
    public function getColor()
    {
        return $this->color;
    }
}
