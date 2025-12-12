<?php

namespace App\Pms\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use DateTimeInterface;

#[ORM\Entity]
#[ORM\Table(name: 'pms_reserva_estado')]
class PmsReservaEstado
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Estado interno del PMS (ej: pendiente, confirmada, cancelada)
    #[ORM\Column(type: 'string', length: 50, unique: true)]
    private ?string $codigo = null;

    // Nombre de visualización
    #[ORM\Column(type: 'string', length: 100)]
    private ?string $nombre = null;

    // Color visual (para listas, calendario)
    #[ORM\Column(type: 'string', length: 7, nullable: true)]
    private ?string $color = null;

    // Código que se envía a Beds24 (ej: confirmed, cancelled)
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $codigoBeds24 = null;

    // Si este estado es terminal (cancelada, no show, etc.)
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private ?bool $esFinal = false;

    // Orden para UI
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $orden = null;

    #[ORM\Column(type: 'datetime')]
    #[Gedmo\Timestampable(on: 'create')]
    private ?DateTimeInterface $created = null;

    #[ORM\Column(type: 'datetime')]
    #[Gedmo\Timestampable(on: 'update')]
    private ?DateTimeInterface $updated = null;

    public function getId(): ?int { return $this->id; }

    public function getCodigo(): ?string { return $this->codigo; }
    public function setCodigo(?string $codigo): self { $this->codigo = $codigo; return $this; }

    public function getNombre(): ?string { return $this->nombre; }
    public function setNombre(?string $nombre): self { $this->nombre = $nombre; return $this; }

    public function getColor(): ?string { return $this->color; }
    public function setColor(?string $color): self { $this->color = $color; return $this; }

    public function getCodigoBeds24(): ?string { return $this->codigoBeds24; }
    public function setCodigoBeds24(?string $codigoBeds24): self { $this->codigoBeds24 = $codigoBeds24; return $this; }

    public function getEsFinal(): ?bool { return $this->esFinal; }
    public function setEsFinal(?bool $esFinal): self { $this->esFinal = $esFinal; return $this; }

    public function getOrden(): ?int { return $this->orden; }
    public function setOrden(?int $orden): self { $this->orden = $orden; return $this; }

    public function getCreated(): ?DateTimeInterface { return $this->created; }
    public function getUpdated(): ?DateTimeInterface { return $this->updated; }

    public function __toString(): string
    {
        return $this->nombre ?? $this->codigo ?? (string) $this->id;
    }
}
