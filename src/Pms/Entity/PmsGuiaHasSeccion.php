<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'pms_guia_has_seccion')]
#[ORM\UniqueConstraint(name: 'uniq_guia_seccion', columns: ['guia_id', 'seccion_id'])]
class PmsGuiaHasSeccion
{
    use IdTrait;
    use TimestampTrait;

    #[ORM\ManyToOne(targetEntity: PmsGuia::class, inversedBy: 'guiaHasSecciones')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE', columnDefinition: 'BINARY(16) COMMENT "(DC2Type:uuid)"')]
    private ?PmsGuia $guia = null;

    #[ORM\ManyToOne(targetEntity: PmsGuiaSeccion::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE', columnDefinition: 'BINARY(16) COMMENT "(DC2Type:uuid)"')]
    #[Assert\NotNull]
    private ?PmsGuiaSeccion $seccion = null;

    #[ORM\Column(type: 'integer')]
    #[Assert\PositiveOrZero]
    private int $orden = 0;

    #[ORM\Column(type: 'boolean')]
    private bool $activo = true;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->orden = 0;
        $this->activo = true;
    }

    public function __toString(): string
    {
        return $this->seccion ? ($this->seccion->getNombreInterno() ?? 'Sección') : 'Nueva Relación';
    }

    // Getters
    public function getGuia(): ?PmsGuia { return $this->guia; }
    public function getSeccion(): ?PmsGuiaSeccion { return $this->seccion; }
    public function getOrden(): int { return $this->orden; }
    public function isActivo(): bool { return $this->activo; }

    // Setters
    public function setGuia(?PmsGuia $guia): self { $this->guia = $guia; return $this; }
    public function setSeccion(?PmsGuiaSeccion $seccion): self { $this->seccion = $seccion; return $this; }
    public function setOrden(int $orden): self { $this->orden = $orden; return $this; }
    public function setActivo(bool $activo): self { $this->activo = $activo; return $this; }
}