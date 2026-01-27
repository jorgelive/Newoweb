<?php

namespace App\Oweb\Entity;

use App\Entity\User;
use DateTime;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Entidad ReservaPago.
 * Gestiona el registro de transacciones financieras vinculadas a las reservas.
 */
#[ORM\Table(name: 'res_pago')]
#[ORM\Entity]
class ReservaPago
{
    /**
     * Identificador autoincremental del pago.
     * @var int
     */
    #[ORM\Column(type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private $id;

    /**
     * Relación con la reserva principal.
     * @var ReservaReserva
     */
    #[ORM\ManyToOne(targetEntity: ReservaReserva::class, inversedBy: 'pagos')]
    #[ORM\JoinColumn(name: 'reserva_id', referencedColumnName: 'id', nullable: false)]
    protected $reserva;

    /**
     * @var DateTime $fecha
     */
    #[ORM\Column(type: 'date')]
    private $fecha;

    /**
     * Relación con la moneda del pago.
     * @var MaestroMoneda
     */
    #[ORM\ManyToOne(targetEntity: MaestroMoneda::class)]
    #[ORM\JoinColumn(name: 'moneda_id', referencedColumnName: 'id', nullable: false)]
    protected $moneda;

    /**
     * @var string
     */
    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: false)]
    private $monto = '00.00';

    /**
     * Usuario que registró o está vinculado al pago.
     * Mapeado a BINARY(16) para coincidir con el identificador UUID de la tabla user.
     * @var User|null
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(
        name: 'user_id',
        referencedColumnName: 'id',
        nullable: true,
        columnDefinition: 'BINARY(16) COMMENT "(DC2Type:uuid)"'
    )]
    protected $user;

    /**
     * Observaciones adicionales del pago.
     * @var string|null
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private $nota;

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

    /**
     * Constructor.
     */
    public function __construct()
    {
    }

    /**
     * Representación textual detallada del pago.
     * @return string
     */
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

    /**
     * Genera un resumen legible de la transacción.
     * @return string|null
     */
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

    /*
     * -------------------------------------------------------------------------
     * GETTERS Y SETTERS EXPLÍCITOS
     * -------------------------------------------------------------------------
     */

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFecha(): ?DateTimeInterface
    {
        return $this->fecha;
    }

    public function setFecha(DateTimeInterface $fecha): self
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

    public function getCreado(): ?DateTimeInterface
    {
        return $this->creado;
    }

    public function setCreado(DateTimeInterface $creado): self
    {
        $this->creado = $creado;

        return $this;
    }

    public function getModificado(): ?DateTimeInterface
    {
        return $this->modificado;
    }

    public function setModificado(DateTimeInterface $modificado): self
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

    /**
     * Obtiene el usuario relacionado.
     * @return User|null
     */
    public function getUser(): ?User
    {
        return $this->user;
    }

    /**
     * Establece el usuario relacionado.
     * @param User|null $user
     * @return self
     */
    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

}