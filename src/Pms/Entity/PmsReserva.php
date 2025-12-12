<?php

namespace App\Pms\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use App\Entity\MaestroMoneda;

#[ORM\Entity]
#[ORM\Table(name: 'pms_reserva')]
class PmsReserva
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $codigoReserva = null;

    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    private ?string $nombreCliente = null;

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $telefono = null;

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $telefono2 = null;

    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $emailCliente = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?DateTimeInterface $fechaLlegada = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?DateTimeInterface $fechaSalida = null;

    #[ORM\ManyToOne(targetEntity: PmsChannel::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?PmsChannel $channel = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $montoTotal = null;

    #[ORM\ManyToOne(targetEntity: MaestroMoneda::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?MaestroMoneda $moneda = null;

    #[ORM\ManyToOne(targetEntity: PmsReservaEstado::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?PmsReservaEstado $estado = null;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $created = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $updated = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCodigoReserva(): ?string
    {
        return $this->codigoReserva;
    }

    public function setCodigoReserva(?string $codigoReserva): self
    {
        $this->codigoReserva = $codigoReserva;

        return $this;
    }

    public function getNombreCliente(): ?string
    {
        return $this->nombreCliente;
    }

    public function setNombreCliente(?string $nombreCliente): self
    {
        $this->nombreCliente = $nombreCliente;

        return $this;
    }

    public function getTelefono(): ?string
    {
        return $this->telefono;
    }

    public function setTelefono(?string $telefono): self
    {
        $this->telefono = $telefono;

        return $this;
    }

    public function getTelefono2(): ?string
    {
        return $this->telefono2;
    }

    public function setTelefono2(?string $telefono2): self
    {
        $this->telefono2 = $telefono2;

        return $this;
    }

    public function getEmailCliente(): ?string
    {
        return $this->emailCliente;
    }

    public function setEmailCliente(?string $emailCliente): self
    {
        $this->emailCliente = $emailCliente;

        return $this;
    }

    public function getFechaLlegada(): ?DateTimeInterface
    {
        return $this->fechaLlegada;
    }

    public function setFechaLlegada(?DateTimeInterface $fechaLlegada): self
    {
        $this->fechaLlegada = $fechaLlegada;

        return $this;
    }

    public function getFechaSalida(): ?DateTimeInterface
    {
        return $this->fechaSalida;
    }

    public function setFechaSalida(?DateTimeInterface $fechaSalida): self
    {
        $this->fechaSalida = $fechaSalida;

        return $this;
    }

    public function getMontoTotal(): ?string
    {
        return $this->montoTotal;
    }

    public function setMontoTotal(?string $montoTotal): self
    {
        $this->montoTotal = $montoTotal;

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

    public function getEstado(): ?PmsReservaEstado
    {
        return $this->estado;
    }

    public function setEstado(?PmsReservaEstado $estado): self
    {
        $this->estado = $estado;

        return $this;
    }

    public function getChannel(): ?PmsChannel
    {
        return $this->channel;
    }

    public function setChannel(?PmsChannel $channel): self
    {
        $this->channel = $channel;
        return $this;
    }

    public function getCreated(): ?DateTimeInterface
    {
        return $this->created;
    }

    public function getUpdated(): ?DateTimeInterface
    {
        return $this->updated;
    }

    public function __toString(): string
    {
        $codigo = $this->codigoReserva ?: ('Reserva #' . $this->id);

        $inicio = $this->fechaLlegada?->format('Y-m-d') ?? 'sin llegada';
        $fin = $this->fechaSalida?->format('Y-m-d') ?? 'sin salida';

        return $codigo . ' (' . $inicio . ' → ' . $fin . ')';
    }
}
