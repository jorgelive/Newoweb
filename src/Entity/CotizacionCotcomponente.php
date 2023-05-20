<?php

namespace App\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * CotizacionCotcomponente
 *
 * @ORM\Table(name="cot_cotcomponente")
 * @ORM\Entity(repositoryClass="App\Repository\CotizacionCotcomponenteRepository")
 */
class CotizacionCotcomponente
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
     * @var \App\Entity\CotizacionCotservicio
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\CotizacionCotservicio", inversedBy="cotcomponentes")
     * @ORM\JoinColumn(name="cotservicio_id", referencedColumnName="id", nullable=false)
     */
    protected $cotservicio;

    /**
     * @var \App\Entity\ServicioComponente
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\ServicioComponente")
     * @ORM\JoinColumn(name="componente_id", referencedColumnName="id", nullable=false)
     */
    protected $componente;

    /**
     * @var \App\Entity\CotizacionEstadocotcomponente
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\CotizacionEstadocotcomponente")
     * @ORM\JoinColumn(name="estadocotcomponente_id", referencedColumnName="id", nullable=false)
     */
    protected $estadocotcomponente;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="App\Entity\CotizacionCottarifa", mappedBy="cotcomponente", cascade={"persist","remove"}, orphanRemoval=true)
     */
    private $cottarifas;

    /**
     * @var int
     *
     * @ORM\Column(name="cantidad", type="integer", options={"default": 1})
     */
    private $cantidad;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="fechahorainicio", type="datetime")
     */
    private $fechahorainicio;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="fechahorafin", type="datetime")
     */
    private $fechahorafin;

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
     * Constructor
     */
    public function __construct()
    {
        $this->cottarifas = new ArrayCollection();
    }

    public function __clone() {
        if($this->id) {
            $this->id = null;
            $this->setCreado(null);
            $this->setModificado(null);
            $newCottarifas = new ArrayCollection();
            foreach($this->cottarifas as $cottarifa) {
                $newCottarifa = clone $cottarifa;
                $newCottarifa->setCotcomponente($this);
                $newCottarifas->add($newCottarifa);
            }
            $this->cottarifas = $newCottarifas;
        }
    }

    /**
     * @return string
     */
    public function __toString()
    {
        if(empty($this->getComponente())){
            return sprintf('id: %s', $this->getId());
        }
        if($this->getCantidad() > 1){
            $infocomponente = sprintf('%s x%s', $this->getComponente()->getNombre(), $this->getCantidad());
        }else{
            $infocomponente = $this->getComponente()->getNombre();
        }
        return $infocomponente;
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

    public function getNombre()
    {
        return $this->getComponente()->getNombre();
    }

    /**
     * Set creado
     *
     * @param \DateTime $creado
     *
     * @return CotizacionCotcomponente
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
     * @return CotizacionCotcomponente
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
     * Set estadocotcomponente
     *
     * @param \App\Entity\CotizacionEstadocotcomponente $estadocotcomponente
     *
     * @return CotizacionCotcomponente
     */
    public function setEstadocotcomponente(\App\Entity\CotizacionEstadocotcomponente $estadocotcomponente)
    {
        $this->estadocotcomponente = $estadocotcomponente;

        return $this;
    }

    /**
     * Get estadocotcomponente
     *
     * @return \App\Entity\CotizacionEstadocotcomponente
     */
    public function getEstadocotcomponente()
    {
        return $this->estadocotcomponente;
    }

    /**
     * Set cotservicio
     *
     * @param \App\Entity\CotizacionCotservicio $cotservicio
     *
     * @return CotizacionCotcomponente
     */
    public function setCotservicio(\App\Entity\CotizacionCotservicio $cotservicio = null)
    {
        $this->cotservicio = $cotservicio;
    
        return $this;
    }

    /**
     * Get cotservicio
     *
     * @return \App\Entity\CotizacionCotservicio
     */
    public function getCotservicio()
    {
        return $this->cotservicio;
    }

    /**
     * Set componente
     *
     * @param \App\Entity\ServicioComponente $componente
     *
     * @return CotizacionCotcomponente
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
     * Add cottarifa
     *
     * @param \App\Entity\CotizacionCottarifa $cottarifa
     *
     * @return CotizacionCotcomponente
     */
    public function addCottarifa(\App\Entity\CotizacionCottarifa $cottarifa)
    {
        $cottarifa->setCotcomponente($this);

        $this->cottarifas[] = $cottarifa;
    
        return $this;
    }

    /**
     * Remove cottarifa
     *
     * @param \App\Entity\CotizacionCottarifa $cottarifa
     */
    public function removeCottarifa(\App\Entity\CotizacionCottarifa $cottarifa)
    {
        $this->cottarifas->removeElement($cottarifa);
    }

    /**
     * Get cottarifas
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getCottarifas()
    {
        return $this->cottarifas;
    }

    /**
     * Set cantidad
     *
     * @param integer $cantidad
     *
     * @return CotizacionCotcomponente
     */
    public function setCantidad($cantidad)
    {
        $this->cantidad = $cantidad;
    
        return $this;
    }

    /**
     * Get cantidad
     *
     * @return integer
     */
    public function getCantidad()
    {
        return $this->cantidad;
    }

    /**
     * Set fechahorainicio
     *
     * @param \DateTime $fechahorainicio
     *
     * @return CotizacionCotcomponente
     */
    public function setFechahorainicio($fechahorainicio)
    {
        $this->fechahorainicio = $fechahorainicio;
    
        return $this;
    }

    /**
     * Get fechahorainicio
     *
     * @return \DateTime
     */
    public function getFechahorainicio()
    {
        return $this->fechahorainicio;
    }

    /**
     * Set fechahorafin.
     *
     * @param \DateTime|null $fechahorafin
     *
     * @return CotizacionCotcomponente
     */
    public function setFechahorafin($fechahorafin = null)
    {
        $this->fechahorafin = $fechahorafin;
    
        return $this;
    }

    /**
     * Get fechahorafin.
     *
     * @return \DateTime|null
     */
    public function getFechahorafin()
    {
        return $this->fechahorafin;
    }
}
