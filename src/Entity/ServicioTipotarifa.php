<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Translatable\Translatable;

/**
 * ServicioTipotarifa
 *
 * @ORM\Table(name="ser_tipotarifa")
 * @ORM\Entity
 * @Gedmo\TranslationEntity(class="App\Entity\ServicioTipotarifaTranslation")
 */
class ServicioTipotarifa implements Translatable
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
     * @var bool
     *
     * @ORM\Column(name="comisionable", type="boolean", options={"default": 1})
     */
    private $comisionable = true;

    /**
     * @var bool
     *
     * @ORM\Column(name="oculto", type="boolean", options={"default": 0})
     */
    private $oculto = false;

    /**
     * @var bool
     *
     * @ORM\Column(name="mostrarcostoincluye", type="boolean", options={"default": 0})
     */
    private $mostrarcostoincluye = false;

    /**
     * @var string
     *
     * @ORM\Column(name="listacolor", type="string", length=30, nullable=true)
     */
    private $listacolor;

    /**
     * @var string
     *
     * @ORM\Column(name="listaclase", type="string", length=30, nullable=true)
     */
    private $listaclase;

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
     * @return ServicioTipotarifa
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
     * Set listacolor
     *
     * @param string $listacolor
     *
     * @return ServicioTipotarifa
     */
    public function setListacolor($listacolor)
    {
        $this->listacolor = $listacolor;

        return $this;
    }

    /**
     * Get listacolor
     *
     * @return string
     */
    public function getListacolor()
    {
        return $this->listacolor;
    }

    /**
     * Set listaclase
     *
     * @param string $listaclase
     *
     * @return ServicioTipotarifa
     */
    public function setListaclase($listaclase)
    {
        $this->listaclase = $listaclase;

        return $this;
    }

    /**
     * Get listacolor
     *
     * @return string
     */
    public function getListaclase()
    {
        return $this->listaclase;
    }


    /**
     * Set creado
     *
     * @param \DateTime $creado
     *
     * @return ServicioTipotarifa
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
     * @return ServicioTipotarifa
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
     * @return ServicioTipotarifa
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
     * Set comisionable.
     *
     * @param bool $comisionable
     *
     * @return ServicioTipotarifa
     */
    public function setComisionable($comisionable)
    {
        $this->comisionable = $comisionable;
    
        return $this;
    }

    /**
     * Get comisionable.
     *
     * @return bool
     */
    public function getComisionable()
    {
        return $this->comisionable;
    }

    /**
     * Set oculto.
     *
     * @param bool $oculto
     *
     * @return ServicioTipotarifa
     */
    public function setOculto($oculto)
    {
        $this->oculto = $oculto;

        return $this;
    }

    /**
     * Get oculto.
     *
     * @return bool
     */
    public function getOculto()
    {
        return $this->oculto;
    }

    /**
     * Set mostrarcostoincluye.
     *
     * @param bool $mostrarcostoincluye
     *
     * @return ServicioTipotarifa
     */
    public function setMostrarcostoincluye($mostrarcostoincluye)
    {
        $this->mostrarcostoincluye = $mostrarcostoincluye;

        return $this;
    }

    /**
     * Get mostrarcostoincluye.
     *
     * @return bool
     */
    public function getMostrarcostoincluye()
    {
        return $this->mostrarcostoincluye;
    }

}
