<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Table(name: 'tra_unidadbitacora')]
#[ORM\Entity]
class TransporteUnidadbitacora
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private $id;

    /**
     * @var \App\Entity\TransporteUnidad
     */
    #[ORM\ManyToOne(targetEntity: 'TransporteUnidad', inversedBy: 'unidadbitacoras')]
    #[ORM\JoinColumn(name: 'unidad_id', referencedColumnName: 'id', nullable: false)]
    private $unidad;

    /**
     * @var \App\Entity\TransporteTipounibit
     */
    #[ORM\ManyToOne(targetEntity: 'TransporteTipounibit')]
    #[ORM\JoinColumn(name: 'tipounibit_id', referencedColumnName: 'id', nullable: false)]
    private $tipounibit;

    #[ORM\Column(type: 'text')]
    private $contenido;

    /**
     * @var int
     */
    #[ORM\Column(name: 'kilometraje', type: 'integer')]
    private $kilometraje;

    /**
     * @var \DateTime
     */
    #[ORM\Column(name: 'fecha', type: 'date')]
    private $fecha;

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
        return $this->getContenido() ?? sprintf("Id: %s.", $this->getId()) ?? '';
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
     * Set contenido
     *
     * @param string $contenido
     *
     * @return TransporteUnidadbitacora
     */
    public function setContenido($contenido)
    {
        $this->contenido = $contenido;

        return $this;
    }

    /**
     * Get contenido
     *
     * @return string
     */
    public function getContenido()
    {
        return $this->contenido;
    }

    /**
     * Set creado
     *
     * @param \DateTime $creado
     *
     * @return TransporteUnidadbitacora
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
     * @return TransporteUnidadbitacora
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
     * Set unidad
     *
     * @param \App\Entity\TransporteUnidad $unidad
     *
     * @return TransporteUnidadbitacora
     */
    public function setUnidad(\App\Entity\TransporteUnidad $unidad = null)
    {
        $this->unidad = $unidad;

        return $this;
    }

    /**
     * Get unidad
     *
     * @return \App\Entity\TransporteUnidad
     */
    public function getUnidad()
    {
        return $this->unidad;
    }

    /**
     * Set tipounibit
     *
     * @param \App\Entity\TransporteTipounibit $tipounibit
     *
     * @return TransporteUnidadbitacora
     */
    public function setTipounibit(\App\Entity\TransporteTipounibit $tipounibit = null)
    {
        $this->tipounibit = $tipounibit;

        return $this;
    }

    /**
     * Get tipounibit
     *
     * @return \App\Entity\TransporteTipounibit
     */
    public function getTipounibit()
    {
        return $this->tipounibit;
    }

    /**
     * Set fecha.
     *
     * @param \DateTime $fecha
     *
     * @return TransporteUnidadbitacora
     */
    public function setFecha($fecha)
    {
        $this->fecha = $fecha;
    
        return $this;
    }

    /**
     * Get fecha.
     *
     * @return \DateTime
     */
    public function getFecha()
    {
        return $this->fecha;
    }

    /**
     * Set kilometraje.
     *
     * @param int $kilometraje
     *
     * @return TransporteUnidadbitacora
     */
    public function setKilometraje($kilometraje)
    {
        $this->kilometraje = $kilometraje;
    
        return $this;
    }

    /**
     * Get kilometraje.
     *
     * @return int
     */
    public function getKilometraje()
    {
        return $this->kilometraje;
    }
}
