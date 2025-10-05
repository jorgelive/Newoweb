<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * CuentaMovimiento
 */
#[ORM\Table(name: 'cue_movimiento')]
#[ORM\Entity]
class CuentaMovimiento
{

    const TIPO_CAMBIO = 3.2;
    /**
     * @var int
     */
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private $id;

    /**
     * @var \App\Entity\CuentaPeriodo
     */
    #[ORM\ManyToOne(targetEntity: 'CuentaPeriodo', inversedBy: 'movimientos')]
    #[ORM\JoinColumn(name: 'periodo_id', referencedColumnName: 'id', nullable: false)]
    protected $periodo;

    /**
     * @var \App\Entity\CuentaPeriodo
     */
    #[ORM\ManyToOne(targetEntity: 'CuentaPeriodo')]
    #[ORM\JoinColumn(name: 'periodotransferencia_id', referencedColumnName: 'id', nullable: true)]
    #[ORM\OrderBy(['modificado' => 'ASC'])]
    protected $periodotransferencia;

    /**
     * @var \App\Entity\UserUser
     */
    #[ORM\ManyToOne(targetEntity: 'UserUser', inversedBy: 'movimientos')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    protected $user;

    /**
     * @var \App\Entity\CuentaCentro
     */
    #[ORM\ManyToOne(targetEntity: 'CuentaCentro', inversedBy: 'movimientos')]
    #[ORM\JoinColumn(name: 'centro_id', referencedColumnName: 'id', nullable: true)]
    protected $centro;

    /**
     * @var \App\Entity\CuentaClase
     */
    #[ORM\ManyToOne(targetEntity: 'CuentaClase', inversedBy: 'movimientos')]
    #[ORM\JoinColumn(name: 'clase_id', referencedColumnName: 'id', nullable: false)]
    protected $clase;

    /**
     * @var \DateTime
     */
    #[ORM\Column(name: 'fechahora', type: 'datetime')]
    private $fechahora;

    /**
     * @var string
     */
    #[ORM\Column(name: 'descripcion', type: 'string', length: 255)]
    private $descripcion;

    /**
     * @var string
     */
    #[ORM\Column(name: 'cobradorpagador', type: 'string', length: 255, nullable: true)]
    private $cobradorpagador;

    /**
     * @var string
     */
    #[ORM\Column(name: 'debe', type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private $debe;

    /**
     * @var string
     */
    private $debesoles;

    /**
     * @var string
     */
    #[ORM\Column(name: 'haber', type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private $haber;

    /**
     * @var string
     */
    private $habersoles;

    /**
     * @var \DateTime $creado
     */
    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private $creado;

    /**
     * @var \DateTime $modificado
     */
    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private $modificado;

    public function __toString()
    {
        return $this->getDescripcion() ?? sprintf("Id: %s.", $this->getId()) ?? '';
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
     * Set fechahora.
     *
     * @param \DateTime $fechahora
     *
     * @return CuentaMovimiento
     */
    public function setFechahora($fechahora)
    {
        $this->fechahora = $fechahora;
    
        return $this;
    }

    /**
     * Get fechahora.
     *
     * @return \DateTime
     */
    public function getFechahora()
    {
        return $this->fechahora;
    }

    /**
     * Set descripcion.
     *
     * @param string $descripcion
     *
     * @return CuentaMovimiento
     */
    public function setDescripcion($descripcion)
    {
        $this->descripcion = $descripcion;
    
        return $this;
    }

    /**
     * Get descripcion.
     *
     * @return string
     */
    public function getDescripcion()
    {
        return $this->descripcion;
    }

    /**
     * Set cobradorpagador.
     *
     * @param string $cobradorpagador
     *
     * @return CuentaMovimiento
     */
    public function setCobradorpagador($cobradorpagador = null)
    {
        $this->cobradorpagador = $cobradorpagador;

        return $this;
    }

    /**
     * Get cobradorpagador.
     *
     * @return string
     */
    public function getCobradorpagador()
    {
        return $this->cobradorpagador;
    }

    /**
     * Set debe.
     *
     * @param string $debe
     *
     * @return CuentaMovimiento
     */
    public function setDebe($debe)
    {
        $this->debe = $debe;
    
        return $this;
    }

    /**
     * Get debe.
     *
     * @return string
     */
    public function getDebe()
    {
        return $this->debe;
    }

    /**
     * Get debesoles.
     *
     * @return string
     */
    public function getDebesoles()
    {

        if(empty($this->getDebe())){
            return $this->getDebe();
        }

        $factor = 1;

        if($this->getPeriodo()->getCuenta()->getMoneda()->getId() != 1){
            $factor = self::TIPO_CAMBIO;
        }
        return $this->debesoles = number_format($this->getDebe() * $factor, 2, '.', '');
    }

    /**
     * Set haber.
     *
     * @param string $haber
     *
     * @return CuentaMovimiento
     */
    public function setHaber($haber)
    {
        $this->haber = $haber;
    
        return $this;
    }

    /**
     * Get haber.
     *
     * @return string
     */
    public function getHaber()
    {
        return $this->haber;
    }

    /**
     * Get habersoles.
     *
     * @return string
     */
    public function getHabersoles()
    {
        if(empty($this->getHaber())){
            return $this->getHaber();
        }

        $factor = 1;

        if($this->getPeriodo()->getCuenta()->getMoneda()->getId() != 1){
            $factor = self::TIPO_CAMBIO;
        }
        return $this->habersoles = number_format($this->getHaber() * $factor, 2, '.', '');
    }

    /**
     * Set creado.
     *
     * @param \DateTime $creado
     *
     * @return CuentaMovimiento
     */
    public function setCreado($creado)
    {
        $this->creado = $creado;
    
        return $this;
    }

    /**
     * Get creado.
     *
     * @return \DateTime
     */
    public function getCreado()
    {
        return $this->creado;
    }

    /**
     * Set modificado.
     *
     * @param \DateTime $modificado
     *
     * @return CuentaMovimiento
     */
    public function setModificado($modificado)
    {
        $this->modificado = $modificado;
    
        return $this;
    }

    /**
     * Get modificado.
     *
     * @return \DateTime
     */
    public function getModificado()
    {
        return $this->modificado;
    }

    /**
     * Set periodo.
     *
     * @param \App\Entity\CuentaPeriodo $periodo
     *
     * @return CuentaMovimiento
     */
    public function setPeriodo(\App\Entity\CuentaPeriodo $periodo)
    {
        $this->periodo = $periodo;
    
        return $this;
    }

    /**
     * Get periodo.
     *
     * @return \App\Entity\CuentaPeriodo
     */
    public function getPeriodo()
    {
        return $this->periodo;
    }

    /**
     * Set user.
     *
     * @param \App\Entity\UserUser $user
     *
     * @return CuentaMovimiento
     */
    public function setUser(\App\Entity\UserUser $user)
    {
        $this->user = $user;
    
        return $this;
    }

    /**
     * Get user.
     *
     * @return \App\Entity\UserUser
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * Set clase.
     *
     * @param \App\Entity\CuentaClase $clase
     *
     * @return CuentaMovimiento
     */
    public function setClase(\App\Entity\CuentaClase $clase)
    {
        $this->clase = $clase;
    
        return $this;
    }

    /**
     * Get clase.
     *
     * @return \App\Entity\CuentaClase
     */
    public function getClase()
    {
        return $this->clase;
    }

    /**
     * Set centro.
     *
     * @param \App\Entity\CuentaCentro $centro
     *
     * @return CuentaMovimiento
     */
    public function setCentro(\App\Entity\CuentaCentro $centro = null)
    {
        $this->centro = $centro;
    
        return $this;
    }

    /**
     * Get centro.
     *
     * @return \App\Entity\CuentaCentro
     */
    public function getCentro()
    {
        return $this->centro;
    }

    /**
     * Set periodotransferencia.
     *
     * @param \App\Entity\CuentaPeriodo|null $periodotransferencia
     *
     * @return CuentaMovimiento
     */
    public function setPeriodotransferencia(\App\Entity\CuentaPeriodo $periodotransferencia = null)
    {
        $this->periodotransferencia = $periodotransferencia;
    
        return $this;
    }

    /**
     * Get periodotransferencia.
     *
     * @return \App\Entity\CuentaPeriodo|null
     */
    public function getPeriodotransferencia()
    {
        return $this->periodotransferencia;
    }
}
