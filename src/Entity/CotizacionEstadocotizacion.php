<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * CotizacionEstadocotizacion
 */
#[ORM\Table(name: 'cot_estadocotizacion')]
#[ORM\Entity]
class CotizacionEstadocotizacion
{
    public const DB_VALOR_PENDIENTE = 1;
    public const DB_VALOR_ARCHIVADO = 2;
    public const DB_VALOR_CONFIRMADO = 3;
    public const DB_VALOR_OPERADO = 4;
    public const DB_VALOR_CANCELADO = 5;
    public const DB_VALOR_PLANTILLA = 6;
    public const DB_VALOR_WAITING = 7;

    /**
     * @var int
     */
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private $id;

    /**
     * @var string
     */
    #[ORM\Column(name: 'nombre', type: 'string', length: 255)]
    private $nombre;

    /**
     * @var bool
     */
    #[ORM\Column(type: 'boolean', options: ['default' => 0])]
    private $nopublico;

    /**
     * @var \DateTime $creado
     */
    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private $creado;

    /**
     * @var \DateTime $modificado
     */
    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
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
     * @return CotizacionEstadocotizacion
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
     * Set nopublico
     *
     * @param boolean $nopublico
     *
     * @return CotizacionEstadocotizacion
     */
    public function setNopublico($nopublico)
    {
        $this->nopublico = $nopublico;

        return $this;
    }

    /**
     * Is nopublico
     *
     * @return boolean
     */
    public function isNopublico(): ?bool
    {
        return $this->nopublico;
    }

    /**
     * Set creado
     *
     * @param \DateTime $creado
     *
     * @return CotizacionEstadocotizacion
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
     * @return CotizacionEstadocotizacion
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
