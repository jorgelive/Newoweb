<?php

declare(strict_types=1);

namespace App\Entity\Maestro;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Api\Controller\TipocambioConsultaController;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Entidad MaestroTipocambio.
 * Registra el histórico de tasas de cambio.
 */
#[ORM\Entity]
#[ORM\Table(name: 'maestro_tipocambio')]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'TipoCambio',
    operations: [
        new Post(
            uriTemplate: '/tipo-cambio/consultar',
            controller: TipocambioConsultaController::class,
            read: false,        // API Platform no buscará el ID en la BD
            deserialize: false, // Nuestro controlador procesará el JSON manualmente
            name: 'api_tipocambio_consultar'
        )
    ],
    routePrefix: '/maestro',
    normalizationContext: ['groups' => ['tipocambio:read']]
)]
class MaestroTipocambio
{
    use IdTrait;
    use TimestampTrait;

    #[ORM\ManyToOne(targetEntity: MaestroMoneda::class)]
    #[ORM\JoinColumn(name: 'moneda_id', referencedColumnName: 'id', nullable: false)]
    #[Groups(['tipocambio:read'])]
    private ?MaestroMoneda $moneda = null;

    #[ORM\Column(type: 'date')]
    #[Groups(['tipocambio:read'])]
    private ?DateTimeInterface $fecha = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 3)]
    #[Groups(['tipocambio:read'])]
    private ?string $compra = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 3)]
    #[Groups(['tipocambio:read'])]
    private ?string $venta = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    /* ======================================================
     * LÓGICA FINANCIERA (Precisión BCMath)
     * ====================================================== */

    #[Groups(['tipocambio:read'])]
    public function getPromedio(): string
    {
        // ⚠️ Aquí SÍ se coalesce, al revés que en `Cotizacion::getGanancia()`, y el motivo es
        // que las columnas son nullable: «sin compra registrada» es un estado válido y su
        // promedio razonable es tratarla como cero. Lo que no se admite es un texto que no sea
        // número, porque eso no es un valor ausente: es un dato corrupto.
        $compra = $this->compra ?? '0.000';
        $venta = $this->venta ?? '0.000';

        // Igual que en `Cotizacion::getGanancia()`: la comprobación va sobre las variables que
        // luego se usan, en el mismo ámbito, o el analizador no estrecha nada.
        if (!is_numeric($compra) || !is_numeric($venta)) {
            throw new \LogicException(sprintf(
                'El tipo de cambio %s tiene valores que no son números (compra «%s», venta «%s»).',
                (string) $this->getId(),
                $compra,
                $venta,
            ));
        }

        $suma = bcadd($compra, $venta, 3);
        return bcdiv($suma, '2', 3);
    }

    #[Groups(['tipocambio:read'])]
    public function getPromedioredondeado(): string
    {
        return (string) round((float) $this->getPromedio(), 2);
    }

    /* ======================================================
     * GETTERS Y SETTERS
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