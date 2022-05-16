<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @ORM\Table(name="mae_tipocambio")
 * @ORM\Entity
 */
class MaestroTipocambio
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var \App\Entity\MaestroMoneda
     *
     * @ORM\ManyToOne(targetEntity="MaestroMoneda", inversedBy="tipocambios")
     * @ORM\JoinColumn(name="moneda_id", referencedColumnName="id", nullable=false)
     */
    protected $moneda;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="fecha", type="date")
     */
    private $fecha;

    /**
     * @var string
     *
     * @ORM\Column(type="decimal", precision=10, scale=3)
     */
    private $compra;

    /**
     * @var string
     *
     * @ORM\Column(type="decimal", precision=10, scale=3)
     */
    private $venta;

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
        if(is_null($this->getFecha())) {
            return sprintf("Id: %s.", $this->getId());
        }

        return $this->getFecha()->format('Y-m-d');
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
     * Set fecha
     *
     * @param \DateTime $fecha
     *
     * @return MaestroTipocambio
     */
    public function setFecha($fecha)
    {
        $this->fecha = $fecha;

        return $this;
    }

    /**
     * Get fecha
     *
     * @return \DateTime
     */
    public function getFecha()
    {
        return $this->fecha;
    }

    /**
     * Set compra
     *
     * @param string $compra
     *
     * @return MaestroTipocambio
     */
    public function setCompra($compra)
    {
        $this->compra = $compra;

        return $this;
    }

    /**
     * Get compra
     *
     * @return string
     */
    public function getCompra()
    {
        return $this->compra;
    }

    /**
     * Set venta
     *
     * @param string $venta
     *
     * @return MaestroTipocambio
     */
    public function setVenta($venta)
    {
        $this->venta = $venta;

        return $this;
    }

    /**
     * Get venta
     *
     * @return string
     */
    public function getVenta()
    {
        return $this->venta;
    }

    /**
     * Set creado
     *
     * @param \DateTime $creado
     *
     * @return MaestroTipocambio
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
     * @return MaestroTipocambio
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
     * Set moneda
     *
     * @param \App\Entity\MaestroMoneda $moneda
     *
     * @return MaestroTipocambio
     */
    public function setMoneda(\App\Entity\MaestroMoneda $moneda = null)
    {
        $this->moneda = $moneda;

        return $this;
    }

    /**
     * Get moneda
     *
     * @return \App\Entity\MaestroMoneda
     */
    public function getMoneda()
    {
        return $this->moneda;
    }
}
