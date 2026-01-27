<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entidad de enlace PmsGuiaHasSeccion.
 * Gestiona la relación de muchos a muchos entre Guías y Secciones,
 * permitiendo definir un orden específico y estado de activación por guía.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pms_guia_has_seccion')]
#[ORM\UniqueConstraint(name: 'uniq_guia_seccion', columns: ['guia_id', 'seccion_id'])]
#[ORM\HasLifecycleCallbacks]
class PmsGuiaHasSeccion
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
     * Relación con la guía principal.
     */
    #[ORM\ManyToOne(targetEntity: PmsGuia::class, inversedBy: 'guiaHasSecciones')]
    #[ORM\JoinColumn(
        name: 'guia_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'CASCADE',
        columnDefinition: 'BINARY(16) COMMENT "(DC2Type:uuid)"'
    )]
    private ?PmsGuia $guia = null;

    /**
     * Relación con la sección de la guía.
     */
    #[ORM\ManyToOne(targetEntity: PmsGuiaSeccion::class)]
    #[ORM\JoinColumn(
        name: 'seccion_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'CASCADE',
        columnDefinition: 'BINARY(16) COMMENT "(DC2Type:uuid)"'
    )]
    private ?PmsGuiaSeccion $seccion = null;

    /**
     * Posición de la sección dentro de la guía.
     * @var int
     */
    #[ORM\Column(type: 'integer')]
    private int $orden = 0;

    /**
     * Indica si esta sección está visible para esta guía específica.
     * @var bool
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $activo = true;

    /*
     * -------------------------------------------------------------------------
     * GETTERS Y SETTERS EXPLÍCITOS (Regla 2026-01-14)
     * -------------------------------------------------------------------------
     */

    public function getGuia(): ?PmsGuia
    {
        return $this->guia;
    }

    public function setGuia(?PmsGuia $guia): self
    {
        $this->guia = $guia;
        return $this;
    }

    public function getSeccion(): ?PmsGuiaSeccion
    {
        return $this->seccion;
    }

    public function setSeccion(?PmsGuiaSeccion $seccion): self
    {
        $this->seccion = $seccion;
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

    public function isActivo(): bool
    {
        return $this->activo;
    }

    public function setActivo(bool $activo): self
    {
        $this->activo = $activo;
        return $this;
    }

    /**
     * Representación textual de la relación.
     */
    public function __toString(): string
    {
        return sprintf('%s - %s',
            $this->guia?->__toString() ?? 'Guía',
            $this->seccion?->getNombreInterno() ?? 'Sección'
        );
    }
}