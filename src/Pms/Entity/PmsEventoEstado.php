<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\Trait\TimestampTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * Entidad PmsEventoEstado.
 * Define los estados internos del PMS y su mapeo con Beds24.
 * IDs Naturales basados en los códigos originales.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pms_evento_estado')]
#[ORM\HasLifecycleCallbacks]
class PmsEventoEstado
{
    /** Gestión de auditoría temporal (createdAt, updatedAt) */
    use TimestampTrait;

    /* ======================================================
     * CONSTANTES DE ID (Valores originales restaurados)
     * ====================================================== */

    public const CODIGO_PENDIENTE      = 'new';
    public const CODIGO_CONFIRMADA     = 'confirmada';
    public const CODIGO_CANCELADA      = 'cancelada';
    public const CODIGO_CONSULTA       = 'consulta';
    public const CODIGO_REQUERIMIENTO  = 'request';
    public const CODIGO_BLOQUEO        = 'bloqueo';

    /**
     * El ID es el código string del estado.
     * Sin GeneratedValue por ser ID Natural.
     */
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 50)]
    private ?string $id = null;

    /**
     * Nombre de visualización para la interfaz.
     */
    #[ORM\Column(type: 'string', length: 100)]
    private ?string $nombre = null;

    /**
     * Color visual en formato hexadecimal (ej: #FF5733).
     */
    #[ORM\Column(type: 'string', length: 7, nullable: true)]
    private ?string $color = null;

    /**
     * Valor que espera Beds24 (ej: "confirmed", "cancelled", "request").
     */
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $codigoBeds24 = null;

    /**
     * Si este estado fuerza su color e ignora otras reglas visuales.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $colorOverride = false;

    /**
     * Orden para listados en la UI.
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

    #[Groups(['pax_reserva:read'])]
    public function getNombre(): ?string { return $this->nombre; }
    public function setNombre(?string $nombre): self { $this->nombre = $nombre; return $this; }

    public function getColor(): ?string { return $this->color; }
    public function setColor(?string $color): self { $this->color = $color; return $this; }

    public function getCodigoBeds24(): ?string { return $this->codigoBeds24; }
    public function setCodigoBeds24(?string $codigoBeds24): self { $this->codigoBeds24 = $codigoBeds24; return $this; }

    public function isColorOverride(): bool { return $this->colorOverride; }
    public function setColorOverride(bool $colorOverride): self { $this->colorOverride = $colorOverride; return $this; }

    public function getOrden(): ?int { return $this->orden; }
    public function setOrden(?int $orden): self { $this->orden = $orden; return $this; }

    public function __toString(): string
    {
        return $this->nombre ?? (string) $this->id;
    }
}