<?php

namespace App\Pms\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity]
#[ORM\Table(name: 'pms_evento_calendario_tipo')]
class PmsEventoCalendarioTipo
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 50, unique: true)]
    private ?string $codigo = null;

    #[ORM\Column(type: 'string', length: 100)]
    private ?string $nombre = null;

    #[ORM\Column(type: 'string', length: 7, nullable: true)]
    private ?string $color = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $orden = null;

    #[ORM\Column(type: 'datetime')]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeInterface $created = null;

    #[ORM\Column(type: 'datetime')]
    #[Gedmo\Timestampable(on: 'update')]
    private ?\DateTimeInterface $updated = null;

    public function getId(): ?int { return $this->id; }
    public function getCodigo(): ?string { return $this->codigo; }
    public function setCodigo(?string $codigo): self { $this->codigo = $codigo; return $this; }
    public function getNombre(): ?string { return $this->nombre; }
    public function setNombre(?string $nombre): self { $this->nombre = $nombre; return $this; }
    public function getColor(): ?string { return $this->color; }
    public function setColor(?string $color): self { $this->color = $color; return $this; }
    public function getOrden(): ?int { return $this->orden; }
    public function setOrden(?int $orden): self { $this->orden = $orden; return $this; }
    public function getCreated(): ?\DateTimeInterface { return $this->created; }
    public function getUpdated(): ?\DateTimeInterface { return $this->updated; }

    public function __toString(): string {
        return $this->nombre ?? $this->codigo ?? (string) $this->id;
    }
}
