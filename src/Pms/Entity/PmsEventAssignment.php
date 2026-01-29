<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\User;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entidad PmsEventAssignment.
 * Une Eventos con Usuarios y Actividades.
 * Corregido para manejar mezcla de UUID v7 e IDs Naturales.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pms_event_assignment')]
#[ORM\HasLifecycleCallbacks]
class PmsEventAssignment
{
    use IdTrait;
    use TimestampTrait;

    /**
     * Relación con el Evento (UUID v7).
     */
    #[ORM\ManyToOne(targetEntity: PmsEventoCalendario::class, inversedBy: 'assignments')]
    #[ORM\JoinColumn(name: 'evento_id', referencedColumnName: 'id', nullable: false, columnDefinition: 'BINARY(16)')]
    private ?PmsEventoCalendario $evento = null;

    /**
     * ✅ CORRECCIÓN CLAVE:
     * Activity usa ID Natural (String), por lo tanto NO debe llevar BINARY(16).
     * Esto resuelve el error SQL 3780 de incompatibilidad.
     */
    #[ORM\ManyToOne(targetEntity: PmsEventAssignmentActivity::class)]
    #[ORM\JoinColumn(name: 'activity_id', referencedColumnName: 'id', nullable: false)]
    private ?PmsEventAssignmentActivity $activity = null;

    /**
     * Relación con el Usuario (UUID v7).
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(
        name: 'usuario_id',
        referencedColumnName: 'id',
        nullable: true,
        columnDefinition: 'BINARY(16)'
    )]
    private ?User $usuario = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $nota = null;

    /*
     * -------------------------------------------------------------------------
     * GETTERS Y SETTERS EXPLÍCITOS
     * -------------------------------------------------------------------------
     */

    public function getEvento(): ?PmsEventoCalendario
    {
        return $this->evento;
    }

    public function setEvento(?PmsEventoCalendario $evento): self
    {
        $this->evento = $evento;
        return $this;
    }

    public function getActivity(): ?PmsEventAssignmentActivity
    {
        return $this->activity;
    }

    public function setActivity(?PmsEventAssignmentActivity $activity): self
    {
        $this->activity = $activity;
        return $this;
    }

    public function getUsuario(): ?User
    {
        return $this->usuario;
    }

    public function setUsuario(?User $usuario): self
    {
        $this->usuario = $usuario;
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

    public function __toString(): string
    {
        return sprintf('%s - %s',
            $this->activity?->getNombre() ?? 'Sin Actividad',
            $this->usuario?->getNombre() ?? 'Sin Usuario'
        );
    }
}