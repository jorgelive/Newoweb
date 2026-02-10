<?php

declare(strict_types=1);

namespace App\Entity\Maestro;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Entidad MaestroTipocambio.
 * Registra el histórico de tasas de cambio.
 * ID Primario: UUID (via IdTrait).
 * Relación: Moneda usa ID Natural (String 3).
 * Sin IDs autoincrementales.
 */
#[ORM\Entity]
#[ORM\Table(name: 'maestro_tipocambio')]
#[ORM\HasLifecycleCallbacks]
class MaestroTipocambio
{
    /** * Gestión de Identificador UUID (BINARY 16).
     * Reemplaza totalmente al ID autoincremental.
     */
    use IdTrait;

    /** Gestión de auditoría (createdAt, updatedAt) */
    use TimestampTrait;

    /**
     * Relación con Moneda: ID Natural String(3) ('USD', 'PEN').
     * Sin BINARY(16) aquí porque el destino es un ID Natural.
     */
    #[ORM\ManyToOne(targetEntity: MaestroMoneda::class)]
    #[ORM\JoinColumn(name: 'moneda_id', referencedColumnName: 'id', nullable: false)]
    private ?MaestroMoneda $moneda = null;

    #[ORM\Column(type: 'date')]
    private ?DateTimeInterface $fecha = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 3)]
    private ?string $compra = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 3)]
    private ?string $venta = null;

    public function __construct()
    {
        // Forzamos la generación del UUID en el constructor si el Trait lo permite
        $this->id = Uuid::v7();
    }

    /* ======================================================
     * LÓGICA DE CÁLCULO (BCMath para precisión financiera)
     * ====================================================== */

    public function getPromedio(): string
    {
        $compra = $this->compra ?? '0.000';
        $venta = $this->venta ?? '0.000';

        $suma = bcadd($compra, $venta, 3);
        return bcdiv($suma, '2', 3);
    }

    public function getPromedioredondeado(): string
    {
        return (string) round((float) $this->getPromedio(), 2);
    }

    /* ======================================================
     * GETTERS Y SETTERS EXPLÍCITOS
     * ====================================================== */

    public function getMoneda(): ?MaestroMoneda { return $this->moneda; }
    public function setMoneda(?MaestroMoneda $moneda): self { $this->moneda = $moneda; return $this; }

    public function getFecha(): ?DateTimeInterface { return $this->fecha; }
    public function setFecha(?DateTimeInterface $fecha): self { $this->fecha = $fecha; return $this; }

    public function getCompra(): ?string { return $this->compra; }
    public function setCompra(?string $compra): self { $this->compra = $compra; return $this; }

    public function getVenta(): ?string { return $this->venta; }
    public function setVenta(?string $venta): self { $this->venta = $venta; return $this; }

    public function __toString(): string
    {
        $fechaStr = $this->fecha ? $this->fecha->format('Y-m-d') : 'S/F';
        $monedaStr = $this->moneda ? $this->moneda->getId() : '???';
        return sprintf('%s - %s (V: %s)', $fechaStr, $monedaStr, $this->venta ?? '0.000');
    }
}