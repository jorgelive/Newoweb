<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\User;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entidad PmsEventAssignment.
 * Gestiona la asignación de usuarios a eventos utilizando identificadores UUID.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pms_event_assignment')]
#[ORM\HasLifecycleCallbacks]
class PmsEventAssignment
{
    /**
     * Gestión de Identificador UUID (BINARY 16).
     */
    use IdTrait;

    /**
     * Gestión de auditoría temporal (DateTimeImmutable).
     */
    use TimestampTrait;

    /**
     * El campo $id ahora es heredado del IdTrait como UUID.
     * Se elimina la definición manual de integer.
     */

    #[ORM\ManyToOne(targetEntity: PmsEventoCalendario::class, inversedBy: 'assignments')]
    #[ORM\JoinColumn(nullable: false)]
    private ?PmsEventoCalendario $evento = null;

    #[ORM\ManyToOne(targetEntity: PmsEventAssignmentActivity::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?PmsEventAssignmentActivity $activity = null;

    /**
     * Relación con el usuario central.
     * Mapeado explícitamente a BINARY(16) y respetando el nombre usuario_id.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(
        name: 'usuario_id',
        referencedColumnName: 'id',
        nullable: true,
        columnDefinition: 'BINARY(16) COMMENT "(DC2Type:uuid)"'
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
}