<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @ORM\Table(name="com_comprobanteitem")
 * @ORM\Entity
 */
class ComprobanteComprobanteitem
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var \App\Entity\ComprobanteComprobante
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\ComprobanteComprobante", inversedBy="comprobanteitems")
     * @ORM\JoinColumn(name="comprobante_id", referencedColumnName="id", nullable=false)
     */
    private $comprobante;

    /**
     * @ORM\Column(type="integer")
     */
    private $cantidad;

    /**
     * @var \App\Entity\ComprobanteProductoservicio
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\ComprobanteProductoservicio")
     * @ORM\JoinColumn(name="productoservicio_id", referencedColumnName="id", nullable=false)
     */
    private $productoservicio;

    /**
     * @ORM\Column(type="decimal", precision=10, scale=2, nullable=false)
     */
    private $unitario;

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
        if(!empty($this->getProductoservicio())){
            return sprintf('%s x %s (%s)', $this->getProductoservicio()->getNombre(), $this->getCantidad(), $this->getUnitario());
        }else{
            return '';
        }

    }

    public function __clone() {
        if ($this->id) {
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
     * Set unitario
     *
     * @param string $unitario
     *
     * @return ComprobanteComprobanteitem
     */
    public function setUnitario($unitario)
    {
        $this->unitario = $unitario;

        return $this;
    }

    /**
     * Get unitario
     *
     * @return string
     */
    public function getUnitario()
    {
        return $this->unitario;
    }

    /**
     * Set creado
     *
     * @param \DateTime $creado
     *
     * @return ComprobanteComprobanteitem
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
     * @return ComprobanteComprobanteitem
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
     * Set comprobante
     *
     * @param \App\Entity\ComprobanteComprobante $comprobante
     *
     * @return ComprobanteComprobanteitem
     */
    public function setComprobante(\App\Entity\ComprobanteComprobante $comprobante)
    {
        $this->comprobante = $comprobante;

        return $this;
    }

    /**
     * Get comprobante
     *
     * @return \App\Entity\ComprobanteComprobante
     */
    public function getComprobante()
    {
        return $this->comprobante;
    }

    /**
     * Set productoservicio
     *
     * @param \App\Entity\ComprobanteProductoservicio $productoservicio
     *
     * @return ComprobanteComprobanteitem
     */
    public function setProductoservicio(\App\Entity\ComprobanteProductoservicio $productoservicio)
    {
        $this->productoservicio = $productoservicio;

        return $this;
    }

    /**
     * Get productoservicio
     *
     * @return \App\Entity\ComprobanteProductoservicio
     */
    public function getProductoservicio()
    {
        return $this->productoservicio;
    }

    /**
     * Set cantidad.
     *
     * @param int $cantidad
     *
     * @return ComprobanteComprobanteitem
     */
    public function setCantidad($cantidad)
    {
        $this->cantidad = $cantidad;
    
        return $this;
    }

    /**
     * Get cantidad.
     *
     * @return int
     */
    public function getCantidad()
    {
        return $this->cantidad;
    }




}
