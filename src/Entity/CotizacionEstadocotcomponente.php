<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * CotizacionEstadocotcomponente
 */
#[ORM\Table(name: 'cot_estadocotcomponente')]
#[ORM\Entity]
class CotizacionEstadocotcomponente
{

    public const DB_VALOR_PENDIENTE = 1;
    public const DB_VALOR_CONFIRMADO = 2;
    public const DB_VALOR_RECONFIRMADO = 3;

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

    #[ORM\Column(type: 'string', length: 10)]
    private $color;

    #[ORM\Column(type: 'string', length: 10)]
    private $colorcalendar;

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
     * @return CotizacionEstadocotcomponente
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
     * Set color
     *
     * @param string $color
     *
     * @return CotizacionEstadocotcomponente
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
     * Set colorcalendar
     *
     * @param string $colorcalendar
     *
     * @return CotizacionEstadocotcomponente
     */
    public function setColorcalendar($colorcalendar)
    {
        $this->colorcalendar = $colorcalendar;

        return $this;
    }

    /**
     * Get colorcalendar
     *
     * @return string
     */
    public function getColorcalendar()
    {
        return $this->colorcalendar;
    }

    /**
     * Set creado
     *
     * @param \DateTime $creado
     *
     * @return CotizacionEstadocotcomponente
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
     * @return CotizacionEstadocotcomponente
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
