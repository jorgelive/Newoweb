<?php
namespace App\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * @ORM\Table(name="tra_unidad")
 * @ORM\Entity
 */
class TransporteUnidad
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=50)
     */
    private $nombre;

    /**
     * @ORM\Column(type="string", length=15)
     */
    private $placa;

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
     * @ORM\OneToMany(targetEntity="TransporteServicio", mappedBy="unidad", cascade={"persist", "remove"}, orphanRemoval=true)
     */
    private $servicios;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="TransporteUnidadbitacora", mappedBy="unidad", cascade={"persist","remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"fecha" = "DESC"})
     */
    private $unidadbitacoras;

    /**
     * @var \App\Entity\CuentaCentro
     *
     * @ORM\OneToOne(targetEntity="App\Entity\CuentaCentro", mappedBy="unidad")
     */
    private $centro;

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->getNombre() ?? sprintf("Id: %s.", $this->getId()) ?? '';
    }

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->servicios = new ArrayCollection();
        $this->unidadbitacoras = new ArrayCollection();
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
     * @return TransporteUnidad
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
     * Set placa
     *
     * @param string $placa
     *
     * @return TransporteUnidad
     */
    public function setPlaca($placa)
    {
        $this->placa = $placa;

        return $this;
    }

    /**
     * Get placa
     *
     * @return string
     */
    public function getPlaca()
    {
        return $this->placa;
    }

    /**
     * Set creado
     *
     * @param \DateTime $creado
     *
     * @return TransporteUnidad
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
     * @return TransporteUnidad
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
     * @return TransporteUnidad
     */
    public function addServicio(\App\Entity\TransporteServicio $servicio)
    {
        $servicio->setUnidad($this);

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
     * @return TransporteUnidad
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
     * Set color
     *
     * @param string $color
     *
     * @return TransporteUnidad
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

    /**
     * Add unidadbitacora.
     *
     * @param \App\Entity\TransporteUnidadbitacora $unidadbitacora
     *
     * @return TransporteUnidad
     */
    public function addUnidadbitacora(\App\Entity\TransporteUnidadbitacora $unidadbitacora)
    {
        $unidadbitacora->setUnidad($this);

        $this->unidadbitacoras[] = $unidadbitacora;
    
        return $this;
    }

    /**
     * Remove unidadbitacora.
     *
     * @param \App\Entity\TransporteUnidadbitacora $unidadbitacora
     *
     * @return boolean TRUE if this collection contained the specified element, FALSE otherwise.
     */
    public function removeUnidadbitacora(\App\Entity\TransporteUnidadbitacora $unidadbitacora)
    {
        return $this->unidadbitacoras->removeElement($unidadbitacora);
    }

    /**
     * Get unidadbitacoras.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getUnidadbitacoras()
    {
        return $this->unidadbitacoras;
    }

    /**
     * Set centro.
     *
     * @param \App\Entity\CuentaCentro|null $centro
     *
     * @return TransporteUnidad
     */
    public function setCentro(\App\Entity\CuentaCentro $centro = null)
    {
        $this->centro = $centro;
    
        return $this;
    }

    /**
     * Get centro.
     *
     * @return \App\Entity\CuentaCentro|null
     */
    public function getCentro()
    {
        return $this->centro;
    }
}
