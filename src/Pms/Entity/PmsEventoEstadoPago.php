<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\Trait\TimestampTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entidad PmsEventoEstadoPago.
 * Define los niveles de cumplimiento de pago de un evento.
 * IDs Naturales con guion-central (ej: pago-parcial).
 */
#[ORM\Entity]
#[ORM\Table(name: 'pms_evento_estado_pago')]
#[ORM\HasLifecycleCallbacks]
class PmsEventoEstadoPago
{
    /** Gestión de auditoría temporal (createdAt, updatedAt) */
    use TimestampTrait;

    /* ======================================================
     * CONSTANTES DE ID (Identificadores naturales con guion)
     * ====================================================== */
    public const ID_SIN_PAGO     = 'no-pagado';
    public const ID_PAGO_PARCIAL = 'pago-parcial';
    public const ID_PAGO_TOTAL   = 'pago-completo';

    /**
     * Clave primaria basada en código natural.
     * Sin GeneratedValue ya que descartamos el patrón autoincremental.
     */
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 50)]
    private ?string $id = null;

    /**
     * Nombre visible para el usuario (ej: "Pago Parcial").
     */
    #[ORM\Column(type: 'string', length: 100)]
    private ?string $nombre = null;

    /**
     * Color visual asociado (HEX: #00FF00).
     */
    #[ORM\Column(type: 'string', length: 7, nullable: true)]
    private ?string $color = null;

    /**
     * Orden de prioridad/aparición en la interfaz.
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $orden = null;

    public function __construct(?string $id = null)
    {
        if ($id) {
            $this->id = $id;
        }
    }

    /* ======================================================
     * LÓGICA DE NORMALIZACIÓN DE COLOR
     * ====================================================== */

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function normalizeColor(): void
    {
        if (empty($this->color)) {
            $this->color = null;
            return;
        }

        $c = trim($this->color);

        // Si viene como "RRGGBB" sin #, lo agregamos
        if (!str_starts_with($c, '#') && preg_match('/^[0-9a-fA-F]{6}$/', $c)) {
            $c = '#' . $c;
        }

        if (str_starts_with($c, '#') && preg_match('/^#[0-9a-fA-F]{6}$/', $c)) {
            $this->color = strtoupper($c);
        } else {
            $this->color = null;
        }
    }

    /* ======================================================
     * GETTERS Y SETTERS
     * ====================================================== */

    public function getId(): ?string { return $this->id; }
    public function setId(string $id): self { $this->id = $id; return $this; }

    public function getNombre(): ?string { return $this->nombre; }
    public function setNombre(?string $nombre): self { $this->nombre = $nombre; return $this; }

    public function getColor(): ?string { return $this->color; }
    public function setColor(?string $color): self { $this->color = $color; return $this; }

    public function getOrden(): ?int { return $this->orden; }
    public function setOrden(?int $orden): self { $this->orden = $orden; return $this; }

    public function __toString(): string
    {
        return $this->nombre ?? (string) $this->id;
    }
}