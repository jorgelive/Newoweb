<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * CotizacionCottarifa
 *
 * @ORM\Table(name="cot_cottarifa")
 * @ORM\Entity
 */
class CotizacionCottarifa
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
     * @var int
     *
     * @ORM\Column(name="cantidad", type="integer")
     */
    private $cantidad;

    /**
     * @var \App\Entity\CotizacionCotcomponente
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\CotizacionCotcomponente", inversedBy="cottarifas")
     * @ORM\JoinColumn(name="cotcomponente_id", referencedColumnName="id", nullable=false)
     */
    protected $cotcomponente;

    /**
     * @var \App\Entity\ServicioTarifa
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\ServicioTarifa")
     * @ORM\JoinColumn(name="tarifa_id", referencedColumnName="id", nullable=false)
     */
    protected $tarifa;

    /**
     * @var \App\Entity\MaestroMoneda
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\MaestroMoneda")
     * @ORM\JoinColumn(name="moneda_id", referencedColumnName="id", nullable=false)
     */
    protected $moneda;

    /**
     * @var string
     *
     * @ORM\Column(name="monto", type="decimal", precision=7, scale=2, nullable=false)
     */
    private $monto;

    /**
     * @var \App\Entity\ServicioTipotarifa
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\ServicioTipotarifa")
     * @ORM\JoinColumn(name="tipotarifa_id", referencedColumnName="id", nullable=false)
     */
    protected $tipotarifa;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="App\Entity\CotizacionCottarifadetalle", mappedBy="cottarifa", cascade={"persist","remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"tipotarifadetalle" = "ASC"})
     */
    private $cottarifadetalles;

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

        if(empty($this->getTarifa())){
            return sprintf("Id: %s.", $this->getId()) ?? '';
        }
        return $this->getTarifa()->getNombre();
    }

    public function __clone() {

        if ($this->id) {
            $this->id = null;
            $this->setCreado(null);
            $this->setModificado(null);
            $newCottarifadetalles = new ArrayCollection();
            foreach ($this->cottarifadetalles as $cottarifadetalle) {
                $newCottarifadetalle = clone $cottarifadetalle;
                $newCottarifadetalle->setCottarifa($this);
                $newCottarifadetalles->add($newCottarifadetalle);
            }
            $this->cottarifadetalles = $newCottarifadetalles;
        }
    }

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->cottarifadetalles = new ArrayCollection();
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
     * Set cantidad
     *
     * @param integer $cantidad
     *
     * @return CotizacionCottarifa
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
     * Set monto
     *
     * @param string $monto
     *
     * @return CotizacionCottarifa
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
     * Set creado
     *
     * @param \DateTime $creado
     *
     * @return CotizacionCottarifa
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
     * @return CotizacionCottarifa
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
     * Set cotcomponente
     *
     * @param \App\Entity\CotizacionCotcomponente $cotcomponente
     *
     * @return CotizacionCottarifa
     */
    public function setCotcomponente(\App\Entity\CotizacionCotcomponente $cotcomponente = null)
    {
        $this->cotcomponente = $cotcomponente;
    
        return $this;
    }

    /**
     * Get cotcomponente
     *
     * @return \App\Entity\CotizacionCotcomponente
     */
    public function getCotcomponente()
    {
        return $this->cotcomponente;
    }

    /**
     * Set tarifa
     *
     * @param \App\Entity\ServicioTarifa $tarifa
     *
     * @return CotizacionCottarifa
     */
    public function setTarifa(\App\Entity\ServicioTarifa $tarifa = null)
    {
        $this->tarifa = $tarifa;
    
        return $this;
    }

    /**
     * Get tarifa
     *
     * @return \App\Entity\ServicioTarifa
     */
    public function getTarifa()
    {
        return $this->tarifa;
    }

    /**
     * Set moneda
     *
     * @param \App\Entity\MaestroMoneda $moneda
     *
     * @return CotizacionCottarifa
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
     * Set tipotarifa
     *
     * @param \App\Entity\ServicioTipotarifa $tipotarifa
     *
     * @return CotizacionCottarifa
     */
    public function setTipotarifa(\App\Entity\ServicioTipotarifa $tipotarifa = null)
    {
        $this->tipotarifa = $tipotarifa;
    
        return $this;
    }

    /**
     * Get tipotarifa
     *
     * @return \App\Entity\ServicioTipotarifa
     */
    public function getTipotarifa()
    {
        return $this->tipotarifa;
    }

    /**
     * Add cottarifadetalle
     *
     * @param \App\Entity\CotizacionCottarifadetalle $cottarifadetalle
     *
     * @return CotizacionCottarifa
     */
    public function addCottarifadetalle(\App\Entity\CotizacionCottarifadetalle $cottarifadetalle)
    {
        $cottarifadetalle->setCotTarifa($this);

        $this->cottarifadetalles[] = $cottarifadetalle;

        return $this;
    }

    /**
     * Remove cottarifadetalle
     *
     * @param \App\Entity\CotizacionCottarifadetalle $cottarifadetalle
     */
    public function removeCottarifadetalle(\App\Entity\CotizacionCottarifadetalle $cottarifadetalle)
    {
        $this->cottarifadetalles->removeElement($cottarifadetalle);
    }

    /**
     * Get cottarifadetalles
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getCottarifadetalles()
    {
        return $this->cottarifadetalles;
    }

}
