<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * ReservaPago
 */
#[ORM\Table(name: 'res_pago')]
#[ORM\Entity]
class ReservaPago
{

    /**
     * @var int
     */
    #[ORM\Column(type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private $id;

    /**
     * @var \App\Entity\ReservaReserva
     */
    #[ORM\ManyToOne(targetEntity: 'ReservaReserva', inversedBy: 'pagos')]
    #[ORM\JoinColumn(name: 'reserva_id', referencedColumnName: 'id', nullable: false)]
    protected $reserva;

    /**
     * @var \DateTime $fecha
     */
    #[ORM\Column(type: 'date')]
    private $fecha;

    /**
     * @var \App\Entity\MaestroMoneda
     */
    #[ORM\ManyToOne(targetEntity: 'MaestroMoneda')]
    #[ORM\JoinColumn(name: 'moneda_id', referencedColumnName: 'id', nullable: false)]
    protected $moneda;

    /**
     * @var string
     */
    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: false)]
    private $monto = '00.00';

    /**
     * @var \App\Entity\UserUser
     */
    #[ORM\ManyToOne(targetEntity: 'UserUser')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true)]
    protected $user;

    /**
     * @var string
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private $nota;

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

    /**
     * Constructor
     */
    public function __construct()
    {
    }

    public function __toString()
    {
        $contenido = [];
        if(!empty($this->getUser())){
            $contenido[] = $this->getUser()->getNombre();
        }
        if(!empty($this->getNota())){
            $contenido[] = $this->getNota();
        }
        return (sprintf('Fecha: %s, Monto: %s %s, Nota: %s', $this->getFecha()->format('Y-m-d'), $this->getMoneda()->getSimbolo(), $this->getMonto(), implode(' ', $contenido)));
    }

    public function getResumen(): ?string
    {
        $contenido = [];
        if(!empty($this->getUser())){
            $contenido[] = $this->getUser()->getNombre();
        }
        if(!empty($this->getNota())){
            $contenido[] = $this->getNota();
        }
        return (sprintf('Fecha: %s, Monto: %s %s, Nota: %s', $this->getFecha()->format('Y-m-d'), $this->getMoneda()->getSimbolo(), $this->getMonto(), implode(' ', $contenido)));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFecha(): ?\DateTimeInterface
    {
        return $this->fecha;
    }

    public function setFecha(\DateTimeInterface $fecha): self
    {
        $this->fecha = $fecha;

        return $this;
    }

    public function getMonto(): ?string
    {
        return $this->monto;
    }

    public function setMonto(string $monto): self
    {
        $this->monto = $monto;

        return $this;
    }

    public function getNota(): ?string
    {
        return $this->nota;
    }

    public function setNota(?string $nota): self
    {
        $this->nota = $nota;

        return $this;
    }

    public function getCreado(): ?\DateTimeInterface
    {
        return $this->creado;
    }

    public function setCreado(\DateTimeInterface $creado): self
    {
        $this->creado = $creado;

        return $this;
    }

    public function getModificado(): ?\DateTimeInterface
    {
        return $this->modificado;
    }

    public function setModificado(\DateTimeInterface $modificado): self
    {
        $this->modificado = $modificado;

        return $this;
    }

    public function getReserva(): ?ReservaReserva
    {
        return $this->reserva;
    }

    public function setReserva(?ReservaReserva $reserva): self
    {
        $this->reserva = $reserva;

        return $this;
    }

    public function getMoneda(): ?MaestroMoneda
    {
        return $this->moneda;
    }

    public function setMoneda(?MaestroMoneda $moneda): self
    {
        $this->moneda = $moneda;

        return $this;
    }

    public function getUser(): ?UserUser
    {
        return $this->user;
    }

    public function setUser(?UserUser $user): self
    {
        $this->user = $user;

        return $this;
    }

}