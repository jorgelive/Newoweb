<?php

declare(strict_types=1);

namespace App\Travel\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
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
use App\Travel\Enum\TarifaCategoriaEnum;
use App\Travel\Enum\TarifaModalidadEnum;
use App\Travel\Enum\TarifaProcedenciaEnum;
use App\Travel\Enum\TarifaRolEnum;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ApiFilter(SearchFilter::class, properties: [
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

    #[Assert\NotNull(message: 'El componente asociado es obligatorio.')]
    #[ORM\ManyToOne(targetEntity: TravelComponente::class, inversedBy: 'tarifas')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?TravelComponente $componente = null;

    #[Groups(['componente:item:read', 'componente:write'])]
    #[Assert\NotBlank(message: 'El nombre interno no puede estar vacío.')]
    #[Assert\Length(
        max: 150,
        maxMessage: 'El nombre interno no puede superar los {{ limit }} caracteres.'
    )]
    #[ORM\Column(type: 'string', length: 150)]
    private ?string $nombreInterno = null;

    #[Groups(['componente:item:read', 'componente:write'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[Assert\NotNull(message: 'El título multiidioma es requerido.')]
    #[Assert\Type(type: 'array', message: 'El título debe ser una estructura de datos válida.')]
    #[ORM\Column(type: 'json')]
    private array $titulo = [];

    #[Groups(['componente:item:read', 'componente:write'])]
    #[Assert\NotBlank(message: 'El monto es obligatorio.')]
    #[Assert\Regex(
        pattern: '/^\d+(\.\d{1,2})?$/',
        message: 'El monto debe ser un número decimal válido con hasta 2 decimales.'
    )]
    #[Assert\PositiveOrZero(message: 'El monto no puede ser negativo.')]
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $monto = '0.00';

    #[Groups(['componente:item:read', 'componente:write'])]
    #[Assert\NotNull(message: 'La moneda es obligatoria.')]
    #[ORM\ManyToOne(targetEntity: MaestroMoneda::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?MaestroMoneda $moneda = null;

    #[Groups(['componente:item:read', 'componente:write'])]
    #[ORM\Column(type: 'string', length: 30, nullable: true, enumType: TarifaModalidadEnum::class)]
    private ?TarifaModalidadEnum $modalidad = null;

    /**
     * Categoría o nivel de confort asociado a la tarifa (ej. Estándar, Económico, Superior, Premium).
     */
    #[Groups(['componente:item:read', 'componente:write'])]
    #[ORM\Column(type: 'string', length: 30, nullable: true, enumType: TarifaCategoriaEnum::class)]
    private ?TarifaCategoriaEnum $categoria = null;

    #[Groups(['componente:item:read', 'componente:write'])]
    #[ORM\Column(type: 'string', length: 30, nullable: true, enumType: TarifaProcedenciaEnum::class)]
    private ?TarifaProcedenciaEnum $procedencia = null;

    #[Groups(['componente:item:read', 'componente:write'])]
    #[Assert\PositiveOrZero(message: 'La edad mínima no puede ser negativa.')]
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $edadMinima = null;

    #[Groups(['componente:item:read', 'componente:write'])]
    #[Assert\PositiveOrZero(message: 'La edad máxima no puede ser negativa.')]
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $edadMaxima = null;

    #[Groups(['componente:item:read', 'componente:write'])]
    #[Assert\PositiveOrZero(message: 'La capacidad mínima no puede ser negativa.')]
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $capacidadMinima = null;

    #[Groups(['componente:item:read', 'componente:write'])]
    #[Assert\PositiveOrZero(message: 'La capacidad máxima no puede ser negativa.')]
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $capacidadMaxima = null;

    #[Groups(['componente:item:read', 'componente:write'])]
    #[Assert\Type(type: 'bool', message: 'El valor de costo por grupo debe ser booleano.')]
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $costoPorGrupo = false;

    #[Groups(['componente:item:read', 'componente:write'])]
    #[Assert\NotNull(message: 'El rol de la tarifa es obligatorio.')]
    #[ORM\Column(type: 'string', length: 20, enumType: TarifaRolEnum::class, options: ['default' => 'estandar'])]
    private TarifaRolEnum $rol = TarifaRolEnum::ESTANDAR;

    #[Groups(['componente:item:read', 'componente:write'])]
    #[Assert\Regex(
        pattern: '/^\d+(\.\d{1,2})?$/',
        message: 'La comisión de anulación/override debe tener un formato decimal válido (ej. 12.34).'
    )]
    #[Assert\Range(
        notInRangeMessage: 'La comisión override debe estar entre el {{ min }}% y el {{ max }}%.',
        min: 0,
        max: 100
    )]
    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true)]
    private ?string $comisionOverride = null; // null = usa la comisión global de la cotización

    #[Groups(['componente:item:read', 'componente:write'])]
    #[ORM\ManyToOne(targetEntity: Proveedor::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Proveedor $proveedor = null;

    /**
     * Relación directa con un servicio específico del proveedor (ej. una habitación o tour exacto).
     * Permite asociar una tarifa a un recurso físico/lógico del proveedor para que salga por defecto en las cotización.
     */
    #[Groups(['componente:item:read', 'componente:write'])]
    #[ORM\ManyToOne(targetEntity: ProveedorServicio::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ProveedorServicio $proveedorServicio = null;

    #[Groups(['componente:item:read', 'componente:write'])]
    #[Assert\Length(
        max: 150,
        maxMessage: 'El nombre para el proveedor no puede superar los {{ limit }} caracteres.'
    )]
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

        $this->componente = null;

        if ($this->nombreInterno) {
            $this->nombreInterno = '(Clon) ' . $this->nombreInterno;
        }
    }

    public function __toString(): string
    {
        if (!$this->nombreInterno) {
            return '✨ Nueva Tarifa';
        }

        $monedaStr = $this->moneda ? $this->moneda->getId() : '';
        $montoStr = $this->monto !== null ? $this->monto : '0.00';
        $etiqueta = sprintf('🏷️ %s | %s %s', $this->nombreInterno, $monedaStr, $montoStr);

        $etiqueta .= $this->costoPorGrupo ? ' 👥' : ' 👤';

        if ($this->procedencia !== null) {
            $etiqueta .= ' ' . $this->getProcedenciaIcono();
        }

        if ($this->edadMinima !== null || $this->edadMaxima !== null) {
            $min = $this->edadMinima ?? '0';
            $max = $this->edadMaxima ?? '∞';
            $etiqueta .= sprintf(' 🎂 (%s-%s años)', $min, $max);
        }

        return $etiqueta;
    }

    /**
     * Icono de procedencia sin texto, para mantener el __toString() compacto
     * en los selects/autocompletes de EasyAdmin.
     *
     * @return string
     */
    private function getProcedenciaIcono(): string
    {
        return match ($this->procedencia) {
            TarifaProcedenciaEnum::NACIONAL => '🇵🇪',
            TarifaProcedenciaEnum::EXTRANJERO => '🌎',
            TarifaProcedenciaEnum::COMUNIDAD_ANDINA => '🤝 CAN',
            default => '',
        };
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

    /**
     * Obtiene el enum de la categoría asociada a la tarifa.
     *
     * @return TarifaCategoriaEnum|null
     */
    public function getCategoria(): ?TarifaCategoriaEnum
    {
        return $this->categoria;
    }

    /**
     * Establece la categoría de confort asociada a la tarifa.
     *
     * @param TarifaCategoriaEnum|null $categoria
     * @return self
     */
    public function setCategoria(?TarifaCategoriaEnum $categoria): self
    {
        $this->categoria = $categoria;
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

    public function getRol(): TarifaRolEnum
    {
        return $this->rol;
    }

    public function setRol(TarifaRolEnum $rol): self
    {
        $this->rol = $rol;
        return $this;
    }

    public function getComisionOverride(): ?string
    {
        return $this->comisionOverride;
    }

    public function setComisionOverride(?string $comisionOverride): self
    {
        $this->comisionOverride = $comisionOverride;
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

    /**
     * Obtiene el servicio específico del proveedor asociado a esta tarifa.
     * Útil para autocompletar o sugerir el servicio (ej. habitación) por defecto en cotización.
     *
     * @return ProveedorServicio|null
     */
    public function getProveedorServicio(): ?ProveedorServicio
    {
        return $this->proveedorServicio;
    }

    /**
     * Establece el servicio específico del proveedor asociado a esta tarifa.
     *
     * @param ProveedorServicio|null $proveedorServicio Entidad del servicio a relacionar.
     * @return self
     */
    public function setProveedorServicio(?ProveedorServicio $proveedorServicio): self
    {
        $this->proveedorServicio = $proveedorServicio;
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

    #[Groups(['componente:item:read'])]
    public function getTarifaId(): ?string
    {
        return $this->getId() ? (string) $this->getId() : null;
    }

    #[Groups(['componente:item:read'])]
    public function getEtiquetaOpciones(): string
    {
        return $this->__toString();
    }

    // 🔥 VIRTUALES PARA EASYADMIN (Evita el HTTP 500 al renderizar HTML personalizado)
    public function getVirtualTitulo(): string
    {
        return '';
    }

    public function getVirtualCostoPorGrupo(): string
    {
        return '';
    }

    public function getVirtualModalidad(): string
    {
        return '';
    }

    public function getVirtualProcedencia(): string
    {
        return '';
    }

    public function getVirtualCategoria(): string
    {
        return '';
    }

    /**
     * Validación lógica cruzada a nivel de entidad.
     * Evita inconsistencias matemáticas y relacionales antes de persistir en BD.
     *
     * @param ExecutionContextInterface $context El contexto de ejecución de validaciones.
     */
    #[Assert\Callback]
    public function validarConsistenciaLogica(ExecutionContextInterface $context): void
    {
        // 1. Validar que la edad máxima no sea inferior a la edad mínima
        if ($this->edadMinima !== null && $this->edadMaxima !== null && $this->edadMaxima < $this->edadMinima) {
            $context->buildViolation('La edad máxima no puede ser inferior a la edad mínima.')
                ->atPath('edadMaxima')
                ->addViolation();
        }

        // 2. Validar que la capacidad máxima no sea inferior a la capacidad mínima
        if ($this->capacidadMinima !== null && $this->capacidadMaxima !== null && $this->capacidadMaxima < $this->capacidadMinima) {
            $context->buildViolation('La capacidad máxima no puede ser inferior a la capacidad mínima.')
                ->atPath('capacidadMaxima')
                ->addViolation();
        }

        // 3. Si se especifica un ProveedorServicio, asegurar que corresponda al mismo Proveedor general
        if ($this->proveedorServicio !== null && $this->proveedor !== null) {
            if ($this->proveedorServicio->getProveedor() !== $this->proveedor) {
                $context->buildViolation('El servicio seleccionado no pertenece al proveedor especificado en la tarifa.')
                    ->atPath('proveedorServicio')
                    ->addViolation();
            }
        }
    }
}