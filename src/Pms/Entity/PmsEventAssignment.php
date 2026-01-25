<?php
declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\User;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity]
#[ORM\Table(name: 'pms_event_assignment')]
class PmsEventAssignment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'assignments')]
    #[ORM\JoinColumn(nullable: false)]
    private ?PmsEventoCalendario $evento = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?PmsEventAssignmentActivity $activity = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $usuario = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $nota = null;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $creado = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $modificado = null;

    public function getId(): ?int
    {
        return $this->id;
    }

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

    public function getCreado(): ?DateTimeInterface
    {
        return $this->creado;
    }

    public function setCreado(?DateTimeInterface $creado): self
    {
        $this->creado = $creado;

        return $this;
    }

    public function getModificado(): ?DateTimeInterface
    {
        return $this->modificado;
    }

    public function setModificado(?DateTimeInterface $modificado): self
    {
        $this->modificado = $modificado;

        return $this;
    }
}