<?php

namespace App\Pms\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use DateTimeInterface;

#[ORM\Entity]
#[ORM\Table(name: 'pms_evento_estado_pago')]
#[ORM\HasLifecycleCallbacks]
class PmsEventoEstadoPago
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Código interno del estado de pago
     * (ej: sin_pago, pago_parcial, pago_total)
     */
    #[ORM\Column(type: 'string', length: 50, unique: true)]
    private ?string $codigo = null;

    /**
     * Nombre visible para el usuario
     */
    #[ORM\Column(type: 'string', length: 100)]
    private ?string $nombre = null;

    /**
     * Color visual (listas, calendario, badges)
     */
    #[ORM\Column(type: 'string', length: 7, nullable: true)]
    private ?string $color = null;

    /**
     * Orden de aparición en UI
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $orden = null;

    #[ORM\Column(type: 'datetime')]
    #[Gedmo\Timestampable(on: 'create')]
    private ?DateTimeInterface $created = null;

    #[ORM\Column(type: 'datetime')]
    #[Gedmo\Timestampable(on: 'update')]
    private ?DateTimeInterface $updated = null;

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    private function normalizeColor(): void
    {
        if ($this->color === null) {
            return;
        }
        $color = trim($this->color);
        if ($color === '') {
            $this->color = null;
            return;
        }
        if (preg_match('/^[0-9a-fA-F]{6}$/', $color)) {
            $color = '#' . $color;
        }
        $color = strtoupper($color);
        $color = substr($color, 0, 7);
        if (!preg_match('/^#[0-9A-F]{6}$/', $color)) {
            $this->color = null;
            return;
        }
        $this->color = $color;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCodigo(): ?string
    {
        return $this->codigo;
    }

    public function setCodigo(?string $codigo): self
    {
        $this->codigo = $codigo;
        return $this;
    }

    public function getNombre(): ?string
    {
        return $this->nombre;
    }

    public function setNombre(?string $nombre): self
    {
        $this->nombre = $nombre;
        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): self
    {
        $this->color = $color;
        return $this;
    }

    public function getOrden(): ?int
    {
        return $this->orden;
    }

    public function setOrden(?int $orden): self
    {
        $this->orden = $orden;
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
        return $this->nombre ?? $this->codigo ?? (string) $this->id;
    }
}