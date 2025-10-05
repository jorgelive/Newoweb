<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * CotizacionCottarifadetalle
 */
#[ORM\Table(name: 'cot_cottarifadetalle')]
#[ORM\Entity]
class CotizacionCottarifadetalle
{
    /**
     * @var int
     */
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private $id;


    /**
     * @var \App\Entity\CotizacionCottarifa
     */
    #[ORM\ManyToOne(targetEntity: 'CotizacionCottarifa', inversedBy: 'cottarifadetalles')]
    #[ORM\JoinColumn(name: 'cottarifa_id', referencedColumnName: 'id', nullable: false)]
    protected $cottarifa;


    /**
     * @var string
     */
    #[ORM\Column(name: 'detalle', type: 'string', length: 255)]
    private $detalle;

    /**
     * @var \App\Entity\ServicioTipotarifadetalle
     */
    #[ORM\ManyToOne(targetEntity: 'ServicioTipotarifadetalle')]
    #[ORM\JoinColumn(name: 'tipotarifadetalle_id', referencedColumnName: 'id', nullable: false)]
    protected $tipotarifadetalle;

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
        return $this->getTipotarifadetalle() . '-' .$this->getDetalle();
    }

    public function __clone() {
        if($this->id) {
            $this->id = null;
            $this->setCreado(null);
            $this->setModificado(null);
        }
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
     * Set detalle
     *
     * @param string $detalle
     *
     * @return CotizacionCottarifadetalle
     */
    public function setDetalle($detalle)
    {
        $this->detalle = $detalle;
    
        return $this;
    }

    /**
     * Get detalle
     *
     * @return string
     */
    public function getDetalle()
    {
        return $this->detalle;
    }

    /**
     * Set creado
     *
     * @param \DateTime $creado
     *
     * @return CotizacionCottarifadetalle
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
     * @return CotizacionCottarifadetalle
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
     * Set cottarifa
     *
     * @param \App\Entity\CotizacionCottarifa $cottarifa
     *
     * @return CotizacionCottarifadetalle
     */
    public function setCottarifa(\App\Entity\CotizacionCottarifa $cottarifa = null)
    {
        $this->cottarifa = $cottarifa;
    
        return $this;
    }

    /**
     * Get cottarifa
     *
     * @return \App\Entity\CotizacionCottarifa
     */
    public function getCottarifa()
    {
        return $this->cottarifa;
    }

    /**
     * Set tipotarifadetalle
     *
     * @param \App\Entity\ServicioTipotarifadetalle $tipotarifadetalle
     *
     * @return CotizacionCottarifadetalle
     */
    public function setTipotarifadetalle(\App\Entity\ServicioTipotarifadetalle $tipotarifadetalle)
    {
        $this->tipotarifadetalle = $tipotarifadetalle;
    
        return $this;
    }

    /**
     * Get tipotarifadetalle
     *
     * @return \App\Entity\ServicioTipotarifadetalle
     */
    public function getTipotarifadetalle()
    {
        return $this->tipotarifadetalle;
    }
}
