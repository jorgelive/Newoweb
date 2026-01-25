<?php

namespace App\Pms\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use DateTimeInterface;

#[ORM\Entity]
#[ORM\Table(name: 'pms_evento_estado')]
#[ORM\HasLifecycleCallbacks]
class PmsEventoEstado
{

/* ======================================================
 * CONSTANTES DE CÓDIGO (estado interno PMS)
 * ====================================================== */

    public const CODIGO_PENDIENTE      = 'new';
    public const CODIGO_CONFIRMADA     = 'confirmada';
    public const CODIGO_CANCELADA      = 'cancelada';
    public const CODIGO_CONSULTA       = 'consulta';
    public const CODIGO_REQUERIMIENTO  = 'requerimiento';
    public const CODIGO_BLOQUEO        = 'bloqueo';

    /**
     * Lista completa de códigos válidos
     */
    public const CODIGOS_VALIDOS = [
        self::CODIGO_PENDIENTE,
        self::CODIGO_CONFIRMADA,
        self::CODIGO_CANCELADA,
        self::CODIGO_CONSULTA,
        self::CODIGO_REQUERIMIENTO,
        self::CODIGO_BLOQUEO,
    ];

    /* ======================================================
     * CAMPOS
     * ====================================================== */

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Estado interno del PMS (ej: pendiente, confirmada, cancelada)
    #[ORM\Column(type: 'string', length: 50, unique: true)]
    private ?string $codigo = null;

    // Nombre de visualización
    #[ORM\Column(type: 'string', length: 100)]
    private ?string $nombre = null;

    // Color visual (para listas, calendario)
    #[ORM\Column(type: 'string', length: 7, nullable: true)]
    private ?string $color = null;

    // Código que se envía a Beds24 (ej: confirmed, cancelled)
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $codigoBeds24 = null;

    // Si este estado fuerza su color visual e ignora el estado de pago
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private ?bool $colorOverride = false;

    // Orden para UI
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $orden = null;

    #[ORM\Column(type: 'datetime')]
    #[Gedmo\Timestampable(on: 'create')]
    private ?DateTimeInterface $created = null;

    #[ORM\Column(type: 'datetime')]
    #[Gedmo\Timestampable(on: 'update')]
    private ?DateTimeInterface $updated = null;

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function normalizeColor(): void
    {
        $c = $this->color;

        if ($c === null) {
            return;
        }

        $c = trim($c);

        // Vacío => null
        if ($c === '') {
            $this->color = null;
            return;
        }

        // Si viene como "RRGGBB" sin #, lo aceptamos
        if (!str_starts_with($c, '#') && preg_match('/^[0-9a-fA-F]{6}$/', $c) === 1) {
            $c = '#' . $c;
        }

        // Hard cap por seguridad (columna length=7)
        if (strlen($c) > 7) {
            $c = substr($c, 0, 7);
        }

        // Validación final: #RRGGBB
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $c) !== 1) {
            $this->color = null;
            return;
        }

        $this->color = strtoupper($c);
    }

    public function getId(): ?int { return $this->id; }

    public function getCodigo(): ?string { return $this->codigo; }
    public function setCodigo(?string $codigo): self { $this->codigo = $codigo; return $this; }

    public function getNombre(): ?string { return $this->nombre; }
    public function setNombre(?string $nombre): self { $this->nombre = $nombre; return $this; }

    public function getColor(): ?string { return $this->color; }
    public function setColor(?string $color): self { $this->color = $color; return $this; }

    public function getCodigoBeds24(): ?string { return $this->codigoBeds24; }
    public function setCodigoBeds24(?string $codigoBeds24): self { $this->codigoBeds24 = $codigoBeds24; return $this; }

    public function isColorOverride(): ?bool { return $this->colorOverride; }
    public function setColorOverride(?bool $colorOverride): self { $this->colorOverride = $colorOverride; return $this; }

    public function getOrden(): ?int { return $this->orden; }
    public function setOrden(?int $orden): self { $this->orden = $orden; return $this; }

    public function getCreated(): ?DateTimeInterface { return $this->created; }
    public function getUpdated(): ?DateTimeInterface { return $this->updated; }

    public function __toString(): string
    {
        return $this->nombre ?? $this->codigo ?? (string) $this->id;
    }
}
