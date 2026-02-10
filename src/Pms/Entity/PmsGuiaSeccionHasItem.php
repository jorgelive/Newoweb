<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'pms_guia_seccion_has_item')]
#[ORM\UniqueConstraint(name: 'uniq_seccion_item', columns: ['seccion_id', 'item_id'])]
class PmsGuiaSeccionHasItem
{
    use IdTrait;
    use TimestampTrait;

    #[ORM\ManyToOne(targetEntity: PmsGuiaSeccion::class, inversedBy: 'seccionHasItems')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE', columnDefinition: 'BINARY(16) COMMENT "(DC2Type:uuid)"')]
    private ?PmsGuiaSeccion $seccion = null;

    #[ORM\ManyToOne(targetEntity: PmsGuiaItem::class, inversedBy: 'itemHasSecciones')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE', columnDefinition: 'BINARY(16) COMMENT "(DC2Type:uuid)"')]
    private ?PmsGuiaItem $item = null;

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
        return $this->item ? $this->item->getNombreInterno() : 'Nueva Asignación';
    }

    // Getters y Setters
    public function getSeccion(): ?PmsGuiaSeccion { return $this->seccion; }
    public function setSeccion(?PmsGuiaSeccion $seccion): self { $this->seccion = $seccion; return $this; }

    public function getItem(): ?PmsGuiaItem { return $this->item; }
    public function setItem(?PmsGuiaItem $item): self { $this->item = $item; return $this; }

    public function getOrden(): int { return $this->orden; }
    public function setOrden(int $orden): self { $this->orden = $orden; return $this; }

    public function isActivo(): bool { return $this->activo; }
    public function setActivo(bool $activo): self { $this->activo = $activo; return $this; }
}