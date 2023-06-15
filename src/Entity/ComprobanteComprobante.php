<?php
namespace App\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @ORM\Table(name="com_comprobante")
 * @ORM\Entity
 */
class ComprobanteComprobante
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var \App\Entity\UserDependencia
     *
     * @ORM\ManyToOne(targetEntity="UserDependencia")
     * @ORM\JoinColumn(name="dependencia_id", referencedColumnName="id", nullable=false)
     */
    private $dependencia;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="ComprobanteComprobanteitem", mappedBy="comprobante", cascade={"persist","remove"}, orphanRemoval=true)
     */
    private $comprobanteitems;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="TransporteServiciocontable", mappedBy="comprobante", cascade={"persist","remove"}, orphanRemoval=true)
     */
    private $serviciocontables;

    /**
     * @ORM\Column(type="string", length=250, nullable=true)
     */
    private $nota;

    /**
     * @var \App\Entity\MaestroMoneda
     *
     * @ORM\ManyToOne(targetEntity="MaestroMoneda")
     * @ORM\JoinColumn(name="moneda_id", referencedColumnName="id", nullable=false)
     */
    private $moneda;

    /**
     * @ORM\Column(type="decimal", precision=10, scale=2, nullable=true)
     */
    private $neto;

    /**
     * @ORM\Column(type="decimal", precision=10, scale=2, nullable=true)
     */
    private $impuesto;

    /**
     * @ORM\Column(type="decimal", precision=10, scale=2, nullable=true)
     */
    private $total;

    /**
     * @var \App\Entity\ComprobanteTipo
     *
     * @ORM\ManyToOne(targetEntity="ComprobanteTipo")
     * @ORM\JoinColumn(name="tipo_id", referencedColumnName="id", nullable=false)
     */
    private $tipo;

    /**
     * @ORM\Column(type="string", length=6, nullable=true)
     */
    private $serie;

    /**
     * @ORM\Column(type="string", length=10, nullable=true)
     */
    private $documento;

    /**
     * @ORM\Column(type="date", nullable=true)
     */
    private $fechaemision;

    /**
     * @ORM\Column(type="string", length=150, nullable=true)
     */
    private $url;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="ComprobanteMensaje", mappedBy="comprobante", cascade={"persist","remove"}, orphanRemoval=true)
     */
    private $mensajes;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="ComprobanteComprobante", mappedBy="original", cascade={"persist","remove"}, orphanRemoval=true)
     */
    private $dependientes;

    /**
     * @var \App\Entity\ComprobanteComprobante
     *
     * @ORM\ManyToOne(targetEntity="ComprobanteComprobante", inversedBy="dependientes")
     */
    private $original;

    /**
     * @var \App\Entity\ComprobanteEstado
     *
     * @ORM\ManyToOne(targetEntity="ComprobanteEstado")
     * @ORM\JoinColumn(name="estado_id", referencedColumnName="id", nullable=false)
     */
    private $estado;

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

    public function __construct()
    {
        $this->comprobanteitems = new ArrayCollection();
        $this->serviciocontables = new ArrayCollection();
        $this->dependientes = new ArrayCollection();
        $this->mensajes = new ArrayCollection();
    }

    public function __clone() {

        if($this->id) {
            $this->id = null;
            $this->setCreado(null);
            $this->setModificado(null);
            $newComprobanteitems = new ArrayCollection();
            foreach($this->comprobanteitems as $comprobanteitem) {
                $newComprobanteitem = clone $comprobanteitem;
                $newComprobanteitem->setComprobante($this);
                $newComprobanteitems->add($newComprobanteitem);
            }
            $this->comprobanteitems = $newComprobanteitems;

            $newServiciocontables = new ArrayCollection();
            foreach($this->serviciocontables as $serviciocontable) {
                $newServiciocontable = clone $serviciocontable;
                $newServiciocontable->setComprobante($this);
                $newServiciocontables->add($newServiciocontable);
            }
            $this->serviciocontables = $newServiciocontables;

            $this->mensajes = new ArrayCollection();
            $this->dependientes = new ArrayCollection();
        }
    }

    /**
     * @return string
     */
    public function __toString()
    {
        if(!empty($this->getDocumento()) && !empty($this->getSerie())){
            return sprintf('%s-%s-%s', $this->getTipo()->getCodigo(), $this->getSerie() , str_pad($this->getDocumento(), 5, "0", STR_PAD_LEFT));
        }else{
            return sprintf("Id: %s.", $this->getId()) ?? '';
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
     * Set nota
     *
     * @param string $nota
     *
     * @return ComprobanteComprobante
     */
    public function setNota($nota)
    {
        $this->nota = $nota;

        return $this;
    }

    /**
     * Get nota
     *
     * @return string
     */
    public function getNota()
    {
        return $this->nota;
    }

    /**
     * Set neto
     *
     * @param string $neto
     *
     * @return ComprobanteComprobante
     */
    public function setNeto($neto)
    {
        $this->neto = $neto;

        return $this;
    }

    /**
     * Get neto
     *
     * @return string
     */
    public function getNeto()
    {
        return $this->neto;
    }

    /**
     * Set impuesto
     *
     * @param string $impuesto
     *
     * @return ComprobanteComprobante
     */
    public function setImpuesto($impuesto)
    {
        $this->impuesto = $impuesto;

        return $this;
    }

    /**
     * Get impuesto
     *
     * @return string
     */
    public function getImpuesto()
    {
        return $this->impuesto;
    }

    /**
     * Set total
     *
     * @param string $total
     *
     * @return ComprobanteComprobante
     */
    public function setTotal($total)
    {
        $this->total = $total;

        return $this;
    }

    /**
     * Get total
     *
     * @return string
     */
    public function getTotal()
    {
        return $this->total;
    }

    /**
     * Set serie
     *
     * @param string $serie
     *
     * @return ComprobanteComprobante
     */
    public function setSerie($serie)
    {
        $this->serie = $serie;

        return $this;
    }

    /**
     * Get serie
     *
     * @return string
     */
    public function getSerie()
    {
        return $this->serie;
    }

    /**
     * Set documento
     *
     * @param string $documento
     *
     * @return ComprobanteComprobante
     */
    public function setDocumento($documento)
    {
        $this->documento = $documento;

        return $this;
    }

    /**
     * Get documento
     *
     * @return string
     */
    public function getDocumento()
    {
        return $this->documento;
    }

    /**
     * Get seriedocumento
     *
     * @return string
     */
    public function getSeriedocumento()
    {
        return sprintf('%s-%s', $this->serie, $this->documento);
    }

    /**
     * Set creado
     *
     * @param \DateTime $creado
     *
     * @return ComprobanteComprobante
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
     * @return ComprobanteComprobante
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
     * Set dependencia
     *
     * @param \App\Entity\UserDependencia $dependencia
     *
     * @return ComprobanteComprobante
     */
    public function setDependencia(\App\Entity\UserDependencia $dependencia)
    {
        $this->dependencia = $dependencia;

        return $this;
    }

    /**
     * Get dependencia
     *
     * @return \App\Entity\UserDependencia
     */
    public function getDependencia()
    {
        return $this->dependencia;
    }

    /**
     * Set moneda
     *
     * @param \App\Entity\MaestroMoneda $moneda
     *
     * @return ComprobanteComprobante
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

    /**
     * Set tipo
     *
     * @param \App\Entity\ComprobanteTipo $tipo
     *
     * @return ComprobanteComprobante
     */
    public function setTipo(\App\Entity\ComprobanteTipo $tipo)
    {
        $this->tipo = $tipo;

        return $this;
    }

    /**
     * Get tipo
     *
     * @return \App\Entity\ComprobanteTipo
     */
    public function getTipo()
    {
        return $this->tipo;
    }

    /**
     * Set estado
     *
     * @param \App\Entity\ComprobanteEstado $estado
     *
     * @return ComprobanteComprobante
     */
    public function setEstado(\App\Entity\ComprobanteEstado $estado = null)
    {
        $this->estado = $estado;

        return $this;
    }

    /**
     * Get estado
     *
     * @return \App\Entity\ComprobanteEstado
     */
    public function getEstado()
    {
        return $this->estado;
    }

    /**
     * Set fechaemision
     *
     * @param \DateTime $fechaemision
     *
     * @return ComprobanteComprobante
     */
    public function setFechaemision($fechaemision)
    {
        $this->fechaemision = $fechaemision;

        return $this;
    }

    /**
     * Get fechaemision
     *
     * @return \DateTime
     */
    public function getFechaemision()
    {
        return $this->fechaemision;
    }

    /**
     * Set url
     *
     * @param string $url
     *
     * @return ComprobanteComprobante
     */
    public function setUrl($url)
    {
        $this->url = $url;

        return $this;
    }

    /**
     * Get url
     *
     * @return string
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * Add dependiente
     *
     * @param \App\Entity\ComprobanteComprobante $dependiente
     *
     * @return ComprobanteComprobante
     */
    public function addDependiente(\App\Entity\ComprobanteComprobante $dependiente)
    {
        $dependiente->setOriginal($this);

        $this->dependientes[] = $dependiente;

        return $this;
    }

    /**
     * Remove dependiente
     *
     * @param \App\Entity\ComprobanteComprobante $dependiente
     */
    public function removeDependiente(\App\Entity\ComprobanteComprobante $dependiente)
    {
        $this->dependientes->removeElement($dependiente);
    }

    /**
     * Get dependientes
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getDependientes()
    {
        return $this->dependientes;
    }

    /**
     * Set original
     *
     * @param \App\Entity\ComprobanteComprobante $original
     *
     * @return ComprobanteComprobante
     */
    public function setOriginal(\App\Entity\ComprobanteComprobante $original = null)
    {
        $this->original = $original;

        return $this;
    }

    /**
     * Get original
     *
     * @return \App\Entity\ComprobanteComprobante
     */
    public function getOriginal()
    {
        return $this->original;
    }


    /**
     * Add mensaje
     *
     * @param \App\Entity\ComprobanteMensaje $mensaje
     *
     * @return ComprobanteComprobante
     */
    public function addMensaje(\App\Entity\ComprobanteMensaje $mensaje)
    {
        $mensaje->setComprobante($this);

        $this->mensajes[] = $mensaje;

        return $this;
    }

    /**
     * Remove mensaje
     *
     * @param \App\Entity\ComprobanteMensaje $mensaje
     */
    public function removeMensaje(\App\Entity\ComprobanteMensaje $mensaje)
    {
        $this->mensajes->removeElement($mensaje);
    }

    /**
     * Get mensajes
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getMensajes()
    {
        return $this->mensajes;
    }

    /**
     * Add serviciocontable.
     *
     * @param \App\Entity\TransporteServiciocontable $serviciocontable
     *
     * @return ComprobanteComprobante
     */
    public function addServiciocontable(\App\Entity\TransporteServiciocontable $serviciocontable)
    {
        $serviciocontable->setComprobante($this);

        $this->serviciocontables[] = $serviciocontable;

        return $this;
    }

    /**
     * Remove serviciocontable.
     *
     * @param \App\Entity\TransporteServiciocontable $serviciocontable
     *
     * @return boolean TRUE if this collection contained the specified element, FALSE otherwise.
     */
    public function removeServiciocontable(\App\Entity\TransporteServiciocontable $serviciocontable)
    {
        return $this->serviciocontables->removeElement($serviciocontable);
    }

    /**
     * Get serviciocontables.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getServiciocontables()
    {
        return $this->serviciocontables;
    }


    /**
     * Add comprobanteitem.
     *
     * @param \App\Entity\ComprobanteComprobanteitem $comprobanteitem
     *
     * @return ComprobanteComprobante
     */
    public function addComprobanteitem(\App\Entity\ComprobanteComprobanteitem $comprobanteitem)
    {
        $comprobanteitem->setComprobante($this);

        $this->comprobanteitems[] = $comprobanteitem;

        return $this;
    }

    /**
     * Remove comprobanteitem.
     *
     * @param \App\Entity\ComprobanteComprobanteitem $comprobanteitem
     *
     * @return boolean TRUE if this collection contained the specified element, FALSE otherwise.
     */
    public function removeComprobanteitem(\App\Entity\ComprobanteComprobanteitem $comprobanteitem)
    {
        return $this->comprobanteitems->removeElement($comprobanteitem);
    }

    /**
     * Get comprobanteitems.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getComprobanteitems()
    {
        return $this->comprobanteitems;
    }

}
