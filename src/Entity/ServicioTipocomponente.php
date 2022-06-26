<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * ServicioTipocomponente
 *
 * @ORM\Table(name="ser_tipocomponente")
 * @ORM\Entity
 */
class ServicioTipocomponente
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
     * @ORM\Column(name="nombre", type="string", length=50)
     */
    private $nombre;

    /**
     * @var bool
     *
     * @ORM\Column(name="dependeduracion", type="boolean", options={"default": 0})
     */
    private $dependeduracion;

    /**
     * @var bool
     *
     * @ORM\Column(name="agendable", type="boolean", options={"default": 0})
     */
    private $agendable;


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
     * @return ServicioTipocomponente
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
     * @return ServicioTipocomponente
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
     * @return ServicioTipocomponente
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
     * Set dependeduracion
     *
     * @param boolean $dependeduracion
     *
     * @return ServicioTipocomponente
     */
    public function setDependeduracion($dependeduracion)
    {
        $this->dependeduracion = $dependeduracion;
    
        return $this;
    }

    /**
     * Is dependeduracion
     *
     * @return boolean
     */
    public function isDependeduracion(): ?bool
    {
        return $this->dependeduracion;
    }


    /**
     * Set agendable
     *
     * @param boolean $agendable
     *
     * @return ServicioTipocomponente
     */
    public function setAgendable($agendable)
    {
        $this->agendable = $agendable;

        return $this;
    }

    /**
     * Is agendable
     *
     * @return boolean
     */
    public function isAgendable(): ?bool
    {
        return $this->agendable;
    }




}
