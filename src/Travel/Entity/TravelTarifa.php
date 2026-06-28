<?php

declare(strict_types=1);

namespace App\Travel\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Attribute\AutoTranslate;
use App\Entity\Maestro\MaestroMoneda;
use App\Entity\Trait\AutoTranslateControlTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Security\Roles;
use App\Travel\Enum\TarifaModalidadEnum;
use App\Travel\Enum\TarifaProcedenciaEnum;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;

#[ApiFilter(SearchFilter::class, properties: [
    'id' => 'exact',
    'nombreInterno' => 'partial'
])]
#[ApiResource(
    shortName: 'Tarifa',
    operations: [
        new Get(
            normalizationContext: ['groups' => ['componente:item:read']],
            security: "is_granted('" . Roles::MAESTROS_SHOW . "')"
        ),
        new GetCollection(
            normalizationContext: ['groups' => ['componente:item:read']],
            security: "is_granted('" . Roles::MAESTROS_SHOW . "')"
        )
    ],
    routePrefix: '/travel'
)]
#[ORM\Entity]
#[ORM\Table(name: 'travel_tarifa')]
#[ORM\HasLifecycleCallbacks]
class TravelTarifa
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait;

    #[ORM\ManyToOne(targetEntity: TravelComponente::class, inversedBy: 'tarifas')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?TravelComponente $componente = null;

    #[Groups(['componente:item:read', 'componente:write'])]
    #[ORM\Column(type: 'string', length: 150)]
    private ?string $nombreInterno = null;

    #[Groups(['componente:item:read', 'componente:write'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $titulo = [];

    #[Groups(['componente:item:read', 'componente:write'])]
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $monto = '0.00';

    #[Groups(['componente:item:read', 'componente:write'])]
    #[ORM\ManyToOne(targetEntity: MaestroMoneda::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?MaestroMoneda $moneda = null;

    #[Groups(['componente:item:read', 'componente:write'])]
    #[ORM\Column(type: 'string', length: 30, enumType: TarifaModalidadEnum::class, nullable: true)]
    private ?TarifaModalidadEnum $modalidad = null;

    #[Groups(['componente:item:read', 'componente:write'])]
    #[ORM\Column(type: 'string', length: 30, enumType: TarifaProcedenciaEnum::class, nullable: true)]
    private ?TarifaProcedenciaEnum $procedencia = null;

    #[Groups(['componente:item:read', 'componente:write'])]
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $edadMinima = null;

    #[Groups(['componente:item:read', 'componente:write'])]
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $edadMaxima = null;

    #[Groups(['componente:item:read', 'componente:write'])]
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $capacidadMinima = null;

    #[Groups(['componente:item:read', 'componente:write'])]
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $capacidadMaxima = null;

    #[Groups(['componente:item:read', 'componente:write'])]
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $costoPorGrupo = false;

    #[Groups(['componente:item:read', 'componente:write'])]
    #[ORM\ManyToOne(targetEntity: Proveedor::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Proveedor $proveedor = null;

    #[Groups(['componente:item:read', 'componente:write'])]
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $nombreParaProveedor = null;

    public function __construct()
    {
        $this->initializeId();
    }

    public function __clone()
    {
        $this->resetId();
        $this->resetTimestamps();
    }

    public function __toString(): string
    {
        if (!$this->nombreInterno) {
            return '✨ Nueva Tarifa';
        }

        $monedaStr = $this->moneda ? $this->moneda->getId() : '';
        $montoStr = $this->monto !== null ? $this->monto : '0.00';
        $etiqueta = sprintf('🏷️ %s | %s %s', $this->nombreInterno, $monedaStr, $montoStr);

        if ($this->costoPorGrupo) {
            $etiqueta .= ' 👥 [Por Grupo]';
        } else {
            $etiqueta .= ' 👤 [Por Pax]';
        }

        if ($this->edadMinima !== null || $this->edadMaxima !== null) {
            $min = $this->edadMinima ?? '0';
            $max = $this->edadMaxima ?? '∞';
            $etiqueta .= sprintf(' 🎂 (%s-%s años)', $min, $max);
        }

        return $etiqueta;
    }

    public function getMonto(): ?string
    {
        return $this->monto;
    }

    public function setMonto(string $monto): self
    {
        $this->monto = $monto;
        return $this;
    }

    public function getComponente(): ?TravelComponente
    {
        return $this->componente;
    }

    public function setComponente(?TravelComponente $componente): void
    {
        $this->componente = $componente;
    }

    public function getNombreInterno(): ?string
    {
        return $this->nombreInterno;
    }

    public function setNombreInterno(?string $nombreInterno): void
    {
        $this->nombreInterno = $nombreInterno;
    }

    public function getTitulo(): array
    {
        return $this->titulo;
    }

    public function setTitulo(array $titulo): void
    {
        $this->titulo = $titulo;
    }

    public function getMoneda(): ?MaestroMoneda
    {
        return $this->moneda;
    }

    public function setMoneda(?MaestroMoneda $moneda): void
    {
        $this->moneda = $moneda;
    }

    public function getModalidad(): ?TarifaModalidadEnum
    {
        return $this->modalidad;
    }

    public function setModalidad(?TarifaModalidadEnum $modalidad): self
    {
        $this->modalidad = $modalidad;
        return $this;
    }

    public function getProcedencia(): ?TarifaProcedenciaEnum
    {
        return $this->procedencia;
    }

    public function setProcedencia(?TarifaProcedenciaEnum $procedencia): self
    {
        $this->procedencia = $procedencia;
        return $this;
    }

    public function getEdadMinima(): ?int
    {
        return $this->edadMinima;
    }

    public function setEdadMinima(?int $edadMinima): self
    {
        $this->edadMinima = $edadMinima;
        return $this;
    }

    public function getEdadMaxima(): ?int
    {
        return $this->edadMaxima;
    }

    public function setEdadMaxima(?int $edadMaxima): self
    {
        $this->edadMaxima = $edadMaxima;
        return $this;
    }

    public function getCapacidadMinima(): ?int
    {
        return $this->capacidadMinima;
    }

    public function setCapacidadMinima(?int $capacidadMinima): void
    {
        $this->capacidadMinima = $capacidadMinima;
    }

    public function getCapacidadMaxima(): ?int
    {
        return $this->capacidadMaxima;
    }

    public function setCapacidadMaxima(?int $capacidadMaxima): void
    {
        $this->capacidadMaxima = $capacidadMaxima;
    }

    public function isCostoPorGrupo(): bool
    {
        return $this->costoPorGrupo;
    }

    public function setCostoPorGrupo(bool $costoPorGrupo): self
    {
        $this->costoPorGrupo = $costoPorGrupo;
        return $this;
    }

    public function getProveedor(): ?Proveedor
    {
        return $this->proveedor;
    }

    public function setProveedor(?Proveedor $proveedor): self
    {
        $this->proveedor = $proveedor;
        return $this;
    }

    public function getNombreParaProveedor(): ?string
    {
        return $this->nombreParaProveedor;
    }

    public function setNombreParaProveedor(?string $nombreParaProveedor): self
    {
        $this->nombreParaProveedor = $nombreParaProveedor;
        return $this;
    }

    /**
     * Expone el identificador de la entidad para respuestas JSON en formato plano.
     * Garantiza que los componentes frontend como TomSelect dispongan del value requerido.
     */
    #[Groups(['componente:item:read'])]
    public function getTarifaId(): ?string
    {
        return $this->getId() ? (string) $this->getId() : null;
    }

    /**
     * 🔥 Expone la representación completa del __toString para el dropdown del frontend.
     */
    #[Groups(['componente:item:read'])]
    public function getEtiquetaOpciones(): string
    {
        return $this->__toString();
    }
}