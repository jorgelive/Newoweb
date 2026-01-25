<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'pms_event_assignment_activity')]
class PmsEventAssignmentActivity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private ?string $nombre = null;

    #[ORM\Column(length: 60, unique: true)]
    private ?string $codigo = null;
    // limpieza, mantenimiento, inspeccion, briefing

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $rol = null;

    // ROLE_LIMPIEZA, ROLE_MANTENIMIENTO

    public function getId(): ?int
    {
        return $this->id;
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

    public function getCodigo(): ?string
    {
        return $this->codigo;
    }

    public function setCodigo(?string $codigo): self
    {
        $this->codigo = $codigo;
        return $this;
    }

    public function getRol(): ?string
    {
        return $this->rol;
    }

    public function setRol(?string $rol): self
    {
        $this->rol = $rol;
        return $this;
    }
}
