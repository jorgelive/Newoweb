<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Translatable\Translatable;

/**
 * ServicioTarifa
 *
 * @ORM\Table(name="ser_tarifa")
 * @ORM\Entity
 * @Gedmo\TranslationEntity(class="App\Entity\ServicioTarifaTranslation")
 */
class ServicioTarifa implements Translatable
{

    /**
     * @var int
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var bool
     *
     * @ORM\Column(name="prorrateado", type="boolean", options={"default": 0})
     */
    private $prorrateado;

    /**
     * @var string
     *
     * @ORM\Column(name="nombre", type="string", length=100)
     */
    private $nombre;

    /**
     * @var string
     *
     * @Gedmo\Translatable
     * @ORM\Column(name="titulo", type="string", length=100, nullable=true)
     */
    private $titulo;

    /**
     * @var \App\Entity\MaestroMoneda
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\MaestroMoneda")
     */
    protected $moneda;

    /**
     * @var string
     *
     * @ORM\Column(name="monto", type="decimal", precision=7, scale=2, nullable=true)
     */
    private $monto;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="validezinicio", type="date")
     */
    private $validezinicio;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="validezfin", type="date")
     */
    private $validezfin;

    /**
     * @var int
     *
     * @ORM\Column(name="capacidadmin", type="integer", nullable=true)
     */
    private $capacidadmin;

    /**
     * @var int
     *
     * @ORM\Column(name="capacidadmax", type="integer", nullable=true)
     */
    private $capacidadmax;

    /**
     * @var int
     *
     * @ORM\Column(name="edadmin", type="integer", nullable=true)
     */
    private $edadmin;

    /**
     * @var int
     *
     * @ORM\Column(name="edadmax", type="integer", nullable=true)
     */
    private $edadmax;

    /**
     * @var \App\Entity\ServicioTipotarifa
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\ServicioTipotarifa")
     * @ORM\JoinColumn(name="tipotarifa_id", referencedColumnName="id", nullable=false)
     */
    protected $tipotarifa;

    /**
     * @var \App\Entity\ServicioComponente
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\ServicioComponente", inversedBy="tarifas")
     * @ORM\JoinColumn(name="componente_id", referencedColumnName="id", nullable=false)
     */
    protected $componente;

    /**
     * @var \App\Entity\MaestroCategoriatour
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\MaestroCategoriatour")
     */
    protected $categoriatour;

    /**
     * @var \App\Entity\MaestroTipopax
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\MaestroTipopax")
     */
    protected $tipopax;

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
     * @Gedmo\Locale
     * Used locale to override Translation listener`s locale
     * this is not a mapped field of entity metadata, just a simple property
     */
    private $locale;

    public function __clone() {
        if ($this->id) {
            $this->id = null;
            $this->setCreado(null);
            $this->setModificado(null);
        }
    }

    /**
     * @return string
     */
    public function __toString()
    {
        $vars = [];
        $varchain = '';
        if(!empty($this->edadmin)){
           $vars[] = '>=' . $this->edadmin;
        }
        if(!empty($this->edadmax)){
            $vars[] = '<=' . $this->edadmax;
        }
        if(!empty($this->getTipopax())
            && !empty($this->getTipopax()->getId())
        ){
            $vars[] = '(' . strtoupper(substr($this->getTipopax()->getNombre(), 0,2) . ')');
        }
        if(count($vars) > 0){
            $varchain = ' | ' . implode(' ', $vars);
        }
        return sprintf('%s%s', $this->getNombre(), $varchain) ?? sprintf("Id: %s.", $this->getId()) ?? '';
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
     * Set prorrateado
     *
     * @param boolean $prorrateado
     *
     * @return ServicioTarifa
     */
    public function setProrrateado($prorrateado)
    {
        $this->prorrateado = $prorrateado;
    
        return $this;
    }

    /**
     * Get prorrateado
     *
     * @return boolean
     */
    public function getProrrateado()
    {
        return $this->prorrateado;
    }

    /**
     * Set nombre
     *
     * @param string $nombre
     *
     * @return ServicioTarifa
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
     * Set monto
     *
     * @param string $monto
     *
     * @return ServicioTarifa
     */
    public function setMonto($monto)
    {
        $this->monto = $monto;
    
        return $this;
    }

    /**
     * Get monto
     *
     * @return string
     */
    public function getMonto()
    {
        return $this->monto;
    }

    /**
     * Set validezinicio
     *
     * @param \DateTime $validezinicio
     *
     * @return ServicioTarifa
     */
    public function setValidezinicio($validezinicio)
    {
        $this->validezinicio = $validezinicio;
    
        return $this;
    }

    /**
     * Get validezinicio
     *
     * @return \DateTime
     */
    public function getValidezinicio()
    {
        return $this->validezinicio;
    }

    /**
     * Set validezfin
     *
     * @param string $validezfin
     *
     * @return ServicioTarifa
     */
    public function setValidezfin($validezfin)
    {
        $this->validezfin = $validezfin;
    
        return $this;
    }

    /**
     * Get validezfin
     *
     * @return string
     */
    public function getValidezfin()
    {
        return $this->validezfin;
    }

    /**
     * Set capacidadmin
     *
     * @param string $capacidadmin
     *
     * @return ServicioTarifa
     */
    public function setCapacidadmin($capacidadmin)
    {
        $this->capacidadmin = $capacidadmin;
    
        return $this;
    }

    /**
     * Get capacidadmin
     *
     * @return string
     */
    public function getCapacidadmin()
    {
        return $this->capacidadmin;
    }

    /**
     * Set capacidadmax
     *
     * @param integer $capacidadmax
     *
     * @return ServicioTarifa
     */
    public function setCapacidadmax($capacidadmax)
    {
        $this->capacidadmax = $capacidadmax;
    
        return $this;
    }

    /**
     * Get capacidadmax
     *
     * @return integer
     */
    public function getCapacidadmax()
    {
        return $this->capacidadmax;
    }

    /**
     * Set edadmin
     *
     * @param integer $edadmin
     *
     * @return ServicioTarifa
     */
    public function setEdadmin($edadmin)
    {
        $this->edadmin = $edadmin;
    
        return $this;
    }

    /**
     * Get edadmin
     *
     * @return integer
     */
    public function getEdadmin()
    {
        return $this->edadmin;
    }

    /**
     * Set edadmax
     *
     * @param integer $edadmax
     *
     * @return ServicioTarifa
     */
    public function setEdadmax($edadmax)
    {
        $this->edadmax = $edadmax;
    
        return $this;
    }

    /**
     * Get edadmax
     *
     * @return integer
     */
    public function getEdadmax()
    {
        return $this->edadmax;
    }

    /**
     * Set creado
     *
     * @param \DateTime $creado
     *
     * @return ServicioTarifa
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
     * @return ServicioTarifa
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
     * Set componente
     *
     * @param \App\Entity\ServicioComponente $componente
     *
     * @return ServicioTarifa
     */
    public function setComponente(\App\Entity\ServicioComponente $componente = null)
    {
        $this->componente = $componente;
    
        return $this;
    }

    /**
     * Get componente
     *
     * @return \App\Entity\ServicioComponente
     */
    public function getComponente()
    {
        return $this->componente;
    }

    /**
     * Set categoriatour
     *
     * @param \App\Entity\MaestroCategoriatour $categoriatour
     *
     * @return ServicioTarifa
     */
    public function setCategoriatour(\App\Entity\MaestroCategoriatour $categoriatour = null)
    {
        $this->categoriatour = $categoriatour;
    
        return $this;
    }

    /**
     * Get categoriatour
     *
     * @return \App\Entity\MaestroCategoriatour
     */
    public function getCategoriatour()
    {
        return $this->categoriatour;
    }

    /**
     * Set tipopax
     *
     * @param \App\Entity\MaestroTipopax $tipopax
     *
     * @return ServicioTarifa
     */
    public function setTipopax(\App\Entity\MaestroTipopax $tipopax = null)
    {
        $this->tipopax = $tipopax;
    
        return $this;
    }

    /**
     * Get tipopax
     *
     * @return \App\Entity\MaestroTipopax
     */
    public function getTipopax()
    {
        return $this->tipopax;
    }

    /**
     * Set moneda
     *
     * @param \App\Entity\MaestroMoneda $moneda
     *
     * @return ServicioTarifa
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
     * Set titulo
     *
     * @param string $titulo
     *
     * @return ServicioTarifa
     */
    public function setTitulo($titulo)
    {
        $this->titulo = $titulo;
    
        return $this;
    }

    /**
     * Get titulo
     *
     * @return string
     */
    public function getTitulo()
    {
        return $this->titulo;
    }

    /**
     * Set tipotarifa.
     *
     * @param \App\Entity\ServicioTipotarifa|null $tipotarifa
     *
     * @return ServicioTarifa
     */
    public function setTipotarifa(\App\Entity\ServicioTipotarifa $tipotarifa = null)
    {
        $this->tipotarifa = $tipotarifa;
    
        return $this;
    }

    /**
     * Get tipotarifa.
     *
     * @return \App\Entity\MaestroTipotarifa|null
     */
    public function getTipotarifa()
    {
        return $this->tipotarifa;
    }
}
