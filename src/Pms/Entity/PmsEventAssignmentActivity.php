<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entidad PmsEventAssignmentActivity.
 * Define el catálogo maestro de actividades (Limpieza, Mantenimiento, Inspección, etc.)
 * que pueden ser asignadas a eventos del calendario.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pms_event_assignment_activity')]
#[ORM\HasLifecycleCallbacks]
class PmsEventAssignmentActivity
{
    /**
     * Gestión de Identificador UUID en formato BINARY(16).
     */
    use IdTrait;

    /**
     * Gestión de auditoría temporal (createdAt, updatedAt) con DateTimeImmutable.
     */
    use TimestampTrait;

    /**
     * Nombre descriptivo de la actividad (ej: 'Limpieza de Salida').
     * @var string|null
     */
    #[ORM\Column(type: 'string', length: 120)]
    private ?string $nombre = null;

    /**
     * Código único para lógica de negocio o integraciones (ej: 'CLEAN_OUT').
     * @var string|null
     */
    #[ORM\Column(type: 'string', length: 60, unique: true)]
    private ?string $codigo = null;

    /**
     * Rol de seguridad necesario para que un usuario pueda realizar esta actividad.
     * Ejemplo: ROLE_MAINTENANCE, ROLE_CLEANING.
     * @var string|null
     */
    #[ORM\Column(type: 'string', length: 80, nullable: true)]
    private ?string $rol = null;

    /*
     * -------------------------------------------------------------------------
     * GETTERS Y SETTERS EXPLÍCITOS
     * -------------------------------------------------------------------------
     */

    /**
     * @return string|null
     */
    public function getNombre(): ?string
    {
        return $this->nombre;
    }

    /**
     * @param string|null $nombre
     * @return self
     */
    public function setNombre(?string $nombre): self
    {
        $this->nombre = $nombre;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getCodigo(): ?string
    {
        return $this->codigo;
    }

    /**
     * @param string|null $codigo
     * @return self
     */
    public function setCodigo(?string $codigo): self
    {
        $this->codigo = $codigo;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getRol(): ?string
    {
        return $this->rol;
    }

    /**
     * @param string|null $rol
     * @return self
     */
    public function setRol(?string $rol): self
    {
        $this->rol = $rol;
        return $this;
    }

    /**
     * Representación textual de la actividad para selectores y logs.
     * @return string
     */
    public function __toString(): string
    {
        return (string) ($this->nombre ?? $this->codigo ?? 'Actividad UUID ' . $this->getId());
    }
}