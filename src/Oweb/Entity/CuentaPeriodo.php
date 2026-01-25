<?php

namespace App\Oweb\Entity;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * CuentaMovimiento
 */
#[ORM\Table(name: 'cue_periodo')]
#[ORM\Entity]
class CuentaPeriodo
{
    /**
     * @var int
     */
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private $id;

    /**
     * @var CuentaCuenta
     */
    #[ORM\ManyToOne(targetEntity: CuentaCuenta::class, inversedBy: 'periodos')]
    #[ORM\JoinColumn(name: 'cuenta_id', referencedColumnName: 'id', nullable: false)]
    protected $cuenta;

    /**
     * @var Collection
     */
    #[ORM\OneToMany(targetEntity: CuentaMovimiento::class, mappedBy: 'periodo', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['fechahora' => 'ASC'])]
    private $movimientos;

    /**
     * @var DateTime
     */
    #[ORM\Column(name: 'fechainicio', type: 'date')]
    private $fechainicio;

    /**
     * @var DateTime
     */
    #[ORM\Column(name: 'fechafin', type: 'date', nullable: true)]
    private $fechafin;

    /**
     * @var DateTime $creado
     */
    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private $creado;

    /**
     * @var DateTime $modificado
     */
    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private $modificado;

    public function __construct() {
        $this->movimientos = new ArrayCollection();
    }

    public function __toString()
    {
        return $this->getNombre();
    }

    /**
     * Get nombre.
     *
     * @return string
     */
    public function getNombre(){

        if(empty($this->fechainicio) || empty($this->getCuenta()) || empty($this->getCuenta()->getNombre())){
            return sprintf("Id: %s.", $this->getId()) ?? '';
        }else{
            $parteInicio = sprintf('del %s', $this->fechainicio->format('Y-m-d'));
        }

        $parteFin = '';

        if(!empty($this->fechafin)){
            $parteFin = sprintf(' al %s', $this->fechafin->format('Y-m-d'));
        }

        return sprintf('%s: %s%s', $this->getCuenta()->getNombre(), $parteInicio, $parteFin);
    }




    /**
     * Get id.
     *
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set fechainicio.
     *
     * @param DateTime $fechainicio
     *
     * @return CuentaPeriodo
     */
    public function setFechainicio($fechainicio)
    {
        $this->fechainicio = $fechainicio;
    
        return $this;
    }

    /**
     * Get fechainicio.
     *
     * @return DateTime
     */
    public function getFechainicio()
    {
        return $this->fechainicio;
    }

    /**
     * Set fechafin.
     *
     * @param DateTime|null $fechafin
     *
     * @return CuentaPeriodo
     */
    public function setFechafin($fechafin = null)
    {
        $this->fechafin = $fechafin;
    
        return $this;
    }

    /**
     * Get fechafin.
     *
     * @return DateTime|null
     */
    public function getFechafin()
    {
        return $this->fechafin;
    }

    /**
     * Set creado.
     *
     * @param DateTime $creado
     *
     * @return CuentaPeriodo
     */
    public function setCreado($creado)
    {
        $this->creado = $creado;
    
        return $this;
    }

    /**
     * Get creado.
     *
     * @return DateTime
     */
    public function getCreado()
    {
        return $this->creado;
    }

    /**
     * Set modificado.
     *
     * @param DateTime $modificado
     *
     * @return CuentaPeriodo
     */
    public function setModificado($modificado)
    {
        $this->modificado = $modificado;
    
        return $this;
    }

    /**
     * Get modificado.
     *
     * @return DateTime
     */
    public function getModificado()
    {
        return $this->modificado;
    }

    /**
     * Set cuenta.
     *
     * @param CuentaCuenta $cuenta
     *
     * @return CuentaPeriodo
     */
    public function setCuenta(CuentaCuenta $cuenta = null) //para que valide el campo deshabilitado de cuenta
    {
        $this->cuenta = $cuenta;
    
        return $this;
    }

    /**
     * Get cuenta.
     *
     * @return CuentaCuenta
     */
    public function getCuenta()
    {
        return $this->cuenta;
    }

    /**
     * Add movimiento.
     *
     * @param CuentaMovimiento $movimiento
     *
     * @return CuentaPeriodo
     */
    public function addMovimiento(CuentaMovimiento $movimiento)
    {
        $movimiento->setPeriodo($this);

        $this->movimientos[] = $movimiento;
    
        return $this;
    }

    /**
     * Remove movimiento.
     *
     * @param CuentaMovimiento $movimiento
     *
     * @return boolean TRUE if this collection contained the specified element, FALSE otherwise.
     */
    public function removeMovimiento(CuentaMovimiento $movimiento)
    {
        return $this->movimientos->removeElement($movimiento);
    }

    /**
     * Get movimientos.
     *
     * @return Collection
     */
    public function getMovimientos()
    {
        return $this->movimientos;
    }
}
