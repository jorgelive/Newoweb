<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\Trait\TimestampTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entidad PmsEventAssignmentActivity.
 * Catálogo maestro de actividades (Limpieza, Mantenimiento, etc.).
 * Uso de ID Natural (Código) y campo de ordenación secuencial.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pms_event_assignment_activity')]
#[ORM\HasLifecycleCallbacks]
class PmsEventAssignmentActivity
{
    /**
     * Gestión de auditoría temporal (createdAt, updatedAt).
     */
    use TimestampTrait;

    /**
     * Identificador Natural (PK).
     * Ejemplo: 'CLEAN_OUT', 'MAINTENANCE', 'INSPECTION'.
     */
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 60)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private ?string $id = null;

    /**
     * Nombre descriptivo de la actividad.
     */
    #[ORM\Column(type: 'string', length: 120)]
    private ?string $nombre = null;

    /**
     * Orden de visualización en selectores y listas.
     */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $orden = 0;

    /**
     * Rol de seguridad necesario para realizar esta actividad.
     * Ejemplo: 'ROLE_MAINTENANCE', 'ROLE_CLEANING'.
     */
    #[ORM\Column(type: 'string', length: 80, nullable: true)]
    private ?string $rol = null;

    /**
     * Constructor.
     */
    public function __construct(?string $id = null)
    {
        if ($id) {
            $this->id = strtoupper($id);
        }
    }

    /*
     * -------------------------------------------------------------------------
     * GETTERS Y SETTERS EXPLÍCITOS (Regla 2026-01-14)
     * -------------------------------------------------------------------------
     */

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(string $id): self
    {
        $this->id = strtoupper($id);
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

    public function getOrden(): int
    {
        return $this->orden;
    }

    public function setOrden(int $orden): self
    {
        $this->orden = $orden;
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

    /**
     * Representación textual.
     */
    public function __toString(): string
    {
        return (string) ($this->nombre ?? $this->id ?? 'Nueva Actividad');
    }
}