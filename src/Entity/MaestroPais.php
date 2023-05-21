<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * MaestroPais
 *
 * @ORM\Table(name="mae_pais")
 * @ORM\Entity
 */
class MaestroPais
{

    public const DB_VALOR_PERU = 117;

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
     * @ORM\Column(type="string", length=100)
     */
    private $nombre;

    /**
     * DCC Cusco
     * @var int
     *
     * @ORM\Column(type="integer", length=3)
     */
    private $codigodcc;

    /**
     * Perurail
     * @var integer
     *
     * @ORM\Column(type="integer", length=3)
     */
    private $codigopr;

    /**
     * Consettur
     * @var integer
     *
     * @ORM\Column(type="integer", length=40)
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
     * @return MaestroPais
     */
    public function setNombre(string $nombre)
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
     * Set codigodcc
     *
     * @param int $codigodcc
     *
     * @return MaestroPais
     */
    public function setCodigodcc(int $codigodcc)
    {
        $this->codigodcc = $codigodcc;

        return $this;
    }

    /**
     * Get codigodcc
     *
     * @return int
     */
    public function getCodigodcc()
    {
        return $this->codigodcc;
    }

    /**
     * Set codigopr
     *
     * @param string $codigopr
     *
     * @return MaestroPais
     */
    public function setCodigopr(string $codigopr)
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
     * @return MaestroPais
     */
    public function setCodigocon(string $codigocon)
    {
        $this->codigocon = $codigocon;

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
     * @return MaestroPais
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
     * @return MaestroPais
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
