<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @ORM\Table(name="com_mensaje")
 * @ORM\Entity
 */
class ComprobanteMensaje
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=50)
     */
    private $clave;

    /**
     * @ORM\Column(type="text")
     */
    private $contenido;

    /**
     * @var \App\Entity\ComprobanteComprobante
     *
     * @ORM\ManyToOne(targetEntity="ComprobanteComprobante", inversedBy="mensajes")
     * @ORM\JoinColumn(name="comprobante_id", referencedColumnName="id", nullable=false)
     */
    private $comprobante;

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
        return sprintf('%s : %s', $this->getClave(), $this->getContenido());
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
     * Set clave
     *
     * @param string $clave
     *
     * @return ComprobanteMensaje
     */
    public function setClave($clave)
    {
        $this->clave = $clave;

        return $this;
    }

    /**
     * Get clave
     *
     * @return string
     */
    public function getClave()
    {
        return $this->clave;
    }

    /**
     * Set contenido
     *
     * @param string $contenido
     *
     * @return ComprobanteMensaje
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
     * @return ComprobanteMensaje
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
     * @return ComprobanteMensaje
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
     * @return ComprobanteMensaje
     */
    public function setComprobante(\App\Entity\ComprobanteComprobante $comprobante = null)
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
}
