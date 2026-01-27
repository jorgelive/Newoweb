<?php
namespace App\Oweb\Entity;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Table(name: 'mae_moneda')]
#[ORM\Entity]
class MaestroMoneda
{
    public const DB_VALOR_SOL = 1;
    public const DB_VALOR_DOLAR = 2;
    public const DB_CODIGO_SOL = 'PEN';
    public const DB_CODIGO_DOLAR = 'USD';

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private $id;

    #[ORM\Column(type: 'string', length: 100)]
    private $nombre;

    #[ORM\Column(type: 'string', length: 10)]
    private $simbolo;

    #[ORM\Column(type: 'string', length: 3)]
    private $codigo;

    /*
     * Legacy para comprobante
    */
    #[ORM\Column(type: 'string', length: 3)]
    private $codigoexterno;

    #[ORM\Column(name: 'prioritario', type: 'boolean', options: ['default' => false])]
    private bool $prioritario = false;

    /**
     * @var Collection
     */
    #[ORM\OneToMany(targetEntity: MaestroTipocambio::class, mappedBy: 'moneda')]
    protected $tipocambios;

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

    public function __construct()
    {
        $this->tipocambios = new ArrayCollection();
    }

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
     * @return MaestroMoneda
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
     * Set simbolo
     *
     * @param string $simbolo
     * @return MaestroMoneda
     */
    public function setSimbolo($simbolo)
    {
        $this->simbolo = $simbolo;

        return $this;
    }

    /**
     * Get simbolo
     *
     * @return string
     */
    public function getSimbolo()
    {
        return $this->simbolo;
    }


    /**
     * Set codigo
     *
     * @param string $codigo
     * @return MaestroMoneda
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
     * Set creado
     *
     * @param DateTime $creado
     * @return MaestroMoneda
     */
    public function setCreado($creado)
    {
        $this->creado = $creado;

        return $this;
    }

    /**
     * Get creado
     *
     * @return DateTime
     */
    public function getCreado()
    {
        return $this->creado;
    }

    /**
     * Set modificado
     *
     * @param DateTime $modificado
     * @return MaestroMoneda
     */
    public function setModificado($modificado)
    {
        $this->modificado = $modificado;

        return $this;
    }

    /**
     * Get modificado
     *
     * @return DateTime
     */
    public function getModificado()
    {
        return $this->modificado;
    }



    /**
     * Set codigoexterno
     *
     * @param string $codigoexterno
     *
     * @return MaestroMoneda
     */
    public function setCodigoexterno($codigoexterno)
    {
        $this->codigoexterno = $codigoexterno;

        return $this;
    }

    /**
     * Get codigoexterno
     *
     * @return string
     */
    public function getCodigoexterno()
    {
        return $this->codigoexterno;
    }

    /**
     * Add tipocambio
     *
     * @param MaestroTipocambio $tipocambio
     *
     * @return MaestroMoneda
     */
    public function addTipocambio(MaestroTipocambio $tipocambio)
    {
        $this->tipocambios[] = $tipocambio;

        return $this;
    }

    /**
     * Remove tipocambio
     *
     * @param MaestroTipocambio $tipocambio
     */
    public function removeTipocambio(MaestroTipocambio $tipocambio)
    {
        $this->tipocambios->removeElement($tipocambio);
    }

    /**
     * Get tipocambios
     *
     * @return Collection
     */
    public function getTipocambios()
    {
        return $this->tipocambios;
    }
}
