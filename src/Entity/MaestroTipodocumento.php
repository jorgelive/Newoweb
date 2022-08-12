<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * MaestroTipodocumento
 *
 * @ORM\Table(name="mae_tipodocumento")
 * @ORM\Entity
 */
class MaestroTipodocumento
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
     * @var string
     *
     * @ORM\Column(type="string", length=100)
     */
    private $nombre;

    /**
     * @var string
     *
     * @ORM\Column(type="string", length=10)
     */
    private $codigo;

    /**
     * DDC Cusco
     * @var int
     *
     * @ORM\Column(type="integer", length=2)
     */
    private $codigoddc;

    /**
     * Perurail
     * @var string
     *
     * @ORM\Column(type="string", length=5)
     */
    private $codigopr;

    /**
     * Consettur
     * @var string
     *
     * @ORM\Column(type="string", length=40)
     */
    private $codigocon;

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
     * @return MaestroTipodocumento
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
     * Set codigo
     *
     * @param string $codigo
     *
     * @return MaestroTipodocumento
     */
    public function setCodigo($codigo)
    {
        $this->codigo = $codigo;
    
        return $this;
    }

    /**
     * Get codigo
     *
     * @return string
     */
    public function getCodigo()
    {
        return $this->codigo;
    }

    /**
     * Set codigoddc
     *
     * @param int $codigoddc
     *
     * @return MaestroTipodocumento
     */
    public function setCodigoddc($codigoddc)
    {
        $this->codigoddc = $codigoddc;

        return $this;
    }

    /**
     * Get codigoddc
     *
     * @return int
     */
    public function getCodigoddc()
    {
        return $this->codigoddc;
    }


    /**
     * Set codigopr
     *
     * @param string $codigopr
     *
     * @return MaestroTipodocumento
     */
    public function setCodigopr($codigopr)
    {
        $this->codigopr = $codigopr;

        return $this;
    }

    /**
     * Get codigopr
     *
     * @return string
     */
    public function getCodigopr()
    {
        return $this->codigopr;
    }

    /**
     * Set codigocon
     *
     * @param string $codigocon
     *
     * @return MaestroTipodocumento
     */
    public function setCodigocon($codigocon)
    {
        $this->codigopr = $codigocon;

        return $this;
    }

    /**
     * Get codigocon
     *
     * @return string
     */
    public function getCodigocon()
    {
        return $this->codigocon;
    }

    /**
     * Set creado
     *
     * @param \DateTime $creado
     *
     * @return MaestroTipodocumento
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
     * @return MaestroTipodocumento
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
}
