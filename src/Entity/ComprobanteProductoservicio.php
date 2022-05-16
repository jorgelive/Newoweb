<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @ORM\Table(name="com_productoservicio")
 * @ORM\Entity
 */
class ComprobanteProductoservicio
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=100)
     */
    private $nombre;

    /**
     * @ORM\Column(type="string", length=50)
     */
    private $codigo;

    /**
     * @ORM\Column(type="string", length=15)
     */
    private $codigosunat;

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
     * @var \App\Entity\ComprobanteTipoproductoservicio
     *
     * @ORM\ManyToOne(targetEntity="ComprobanteTipoproductoservicio")
     * @ORM\JoinColumn(name="tipoproductoservicio_id", referencedColumnName="id", nullable=false)
     */
    private $tipoproductoservicio;

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
     * @return ComprobanteProductoservicio
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
     * @return ComprobanteProductoservicio
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
     * Set codigosunat
     *
     * @param string $codigosunat
     * @return ComprobanteProductoservicio
     */
    public function setCodigosunat($codigosunat)
    {
        $this->codigosunat = $codigosunat;

        return $this;
    }

    /**
     * Get codigosunat
     *
     * @return string
     */
    public function getCodigosunat()
    {
        return $this->codigosunat;
    }

    /**
     * Set creado
     *
     * @param \DateTime $creado
     * @return ComprobanteProductoservicio
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
     * @return ComprobanteProductoservicio
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
     * Set tipoproductoservicio
     *
     * @param \App\Entity\ComprobanteTipoproductoservicio $tipoproductoservicio
     *
     * @return ComprobanteProductoservicio
     */
    public function setTipoproductoservicio(\App\Entity\ComprobanteTipoproductoservicio $tipoproductoservicio)
    {
        $this->tipoproductoservicio = $tipoproductoservicio;

        return $this;
    }

    /**
     * Get tipoproductoservicio
     *
     * @return \App\Entity\ComprobanteTipoproductoservicio
     */
    public function getTipoproductoservicio()
    {
        return $this->tipoproductoservicio;
    }

    
}
