<?php

namespace App\Oweb\Entity;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * CuentaCuenta
 */
#[ORM\Table(name: 'cue_cuenta')]
#[ORM\Entity]
class CuentaCuenta
{
    /**
     * @var int
     */
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private $id;

    /**
     * @var string
     */
    #[ORM\Column(name: 'nombre', type: 'string', length: 100)]
    private $nombre;

    /**
     * @var MaestroMoneda
     */
    #[ORM\ManyToOne(targetEntity: MaestroMoneda::class)]
    #[ORM\JoinColumn(name: 'moneda_id', referencedColumnName: 'id', nullable: false)]
    protected $moneda;

    /**
     * @var Collection
     */
    #[ORM\OneToMany(targetEntity: CuentaPeriodo::class, mappedBy: 'cuenta', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['fechainicio' => 'DESC'])]
    private $periodos;

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
        $this->periodos = new ArrayCollection();
    }

    public function __toString()
    {
        return $this->getNombre() ?? sprintf("Id: %s.", $this->getId()) ?? '';
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
     * Set nombre.
     *
     * @param string $nombre
     *
     * @return CuentaCuenta
     */
    public function setNombre($nombre)
    {
        $this->nombre = $nombre;
    
        return $this;
    }

    /**
     * Get nombre.
     *
     * @return string
     */
    public function getNombre()
    {
        return $this->nombre;
    }

    /**
     * Set creado.
     *
     * @param DateTime $creado
     *
     * @return CuentaCuenta
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
     * @return CuentaCuenta
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
     * Set moneda.
     *
     * @param MaestroMoneda $moneda
     *
     * @return CuentaCuenta
     */
    public function setMoneda(MaestroMoneda $moneda)
    {
        $this->moneda = $moneda;
    
        return $this;
    }

    /**
     * Get moneda.
     *
     * @return MaestroMoneda
     */
    public function getMoneda()
    {
        return $this->moneda;
    }

    /**
     * Add periodo.
     *
     * @param CuentaPeriodo $periodo
     *
     * @return CuentaCuenta
     */
    public function addPeriodo(CuentaPeriodo $periodo)
    {
        $periodo->setCuenta($this);

        $this->periodos[] = $periodo;
    
        return $this;
    }

    /**
     * Remove periodo.
     *
     * @param CuentaPeriodo $periodo
     *
     * @return boolean TRUE if this collection contained the specified element, FALSE otherwise.
     */
    public function removePeriodo(CuentaPeriodo $periodo)
    {
        return $this->periodos->removeElement($periodo);
    }

    /**
     * Get periodos.
     *
     * @return Collection
     */
    public function getPeriodos()
    {
        return $this->periodos;
    }
}
