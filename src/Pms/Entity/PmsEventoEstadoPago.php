<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Entity\Trait\TimestampTrait;
use App\Security\Roles;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Entidad PmsEventoEstadoPago.
 * Define los niveles de cumplimiento de pago de un evento.
 * IDs Naturales con guion-central (ej: pago-parcial).
 */
#[ApiResource(
    operations: [
        // Solo lectura: alimenta el selector de estado de pago del calendario SPA (Vue).
        new GetCollection(
            security: "is_granted('" . Roles::RESERVAS_SHOW . "')",
            normalizationContext: ['groups' => ['pms_evento_estado_pago:read']],
        ),
    ],
    routePrefix: '/pms',
    order: ['orden' => 'ASC'],
    paginationEnabled: false,
)]
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
    public const ID_PAGO_ALOJAMIENTO = 'pago-alojamiento';
    public const ID_PAGO_PARCIAL = 'pago-parcial';
    public const ID_PAGO_TOTAL   = 'pago-total';

    public const ESTADOS_PAGO_CONFIABLES = [
        self::ID_PAGO_PARCIAL,
        self::ID_PAGO_TOTAL,
        self::ID_PAGO_ALOJAMIENTO
    ];

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
     * Si este estado fuerza su color e ignora otras reglas visuales.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $colorOverride = false;

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

    #[Groups(['pms_evento_estado_pago:read', 'pms_evento:read'])]
    public function getId(): ?string { return $this->id; }
    public function setId(string $id): self { $this->id = $id; return $this; }

    #[Groups(['pms_evento_estado_pago:read', 'pms_evento:read'])]
    public function getNombre(): ?string { return $this->nombre; }
    public function setNombre(?string $nombre): self { $this->nombre = $nombre; return $this; }

    #[Groups(['pms_evento_estado_pago:read', 'pms_evento:read'])]
    public function getColor(): ?string { return $this->color; }
    public function setColor(?string $color): self { $this->color = $color; return $this; }

    #[Groups(['pms_evento_estado_pago:read', 'pms_evento:read'])]
    public function isColorOverride(): bool { return $this->colorOverride; }
    public function setColorOverride(bool $colorOverride): self { $this->colorOverride = $colorOverride; return $this; }

    #[Groups(['pms_evento_estado_pago:read'])]
    public function getOrden(): ?int { return $this->orden; }
    public function setOrden(?int $orden): self { $this->orden = $orden; return $this; }

    public function __toString(): string
    {
        return $this->nombre ?? (string) $this->id;
    }
}