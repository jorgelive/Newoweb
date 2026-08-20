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

    /** @var list<array{language?: string, content?: string|null}> */
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
    #[Assert\Length(
        max: 150,
        maxMessage: 'El nombre para el prestador no puede superar los {{ limit }} caracteres.'
    )]
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $nombreParaPrestador = null;

    // ─────────────────────────────────────────────────────────────────────────
    // LOS TRES ROLES, POR LÍNEA DE PRECIO
    //
    // Aquí y no en `TravelComponente`, y esto ya dio dos vueltas: un componente **puede
    // tener tarifas de empresas distintas** —«Tren Ollanta – Machu Picchu» se compra a
    // PeruRail y a IncaRail—, así que una empresa única arriba era una afirmación falsa
    // sobre todas las tarifas menos una. Ver `docs/Travel.md` §11.
    //
    // Que el campo estuviera vacío cuando vivía aquí (5 de 904) no significaba que
    // estuviera en el sitio equivocado: significaba que nadie tenía todavía un motivo para
    // llenarlo. El motivo llegó con la Orden de Servicio, que sale a nombre del COMPRADOR.
    //
    // `nombreParaPrestador`, justo encima, es de esta misma idea y nunca se movió: es la
    // pista de que el sitio era éste.
    // ─────────────────────────────────────────────────────────────────────────

    /** Quién PRESTA el servicio: de quién es este precio. */
    #[Groups(['componente:item:read', 'componente:write'])]
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: TravelOrganizacion::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TravelOrganizacion $prestador = null;

    /** El servicio concreto que se le compra (ej. el tipo de habitación). */
    #[Groups(['componente:item:read', 'componente:write'])]
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: TravelOrganizacionServicio::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TravelOrganizacionServicio $prestadorServicio = null;

    /**
     * A quién se le MANDA el encargo de comprar.
     *
     * ⚠️ **Vacío significa «se le compra al prestador»**, que es el caso normal — por eso
     * puede quedarse sin llenar en casi todas las tarifas. Sólo se rellena cuando el encargo
     * va a otro: le compras a Futurismo las entradas que presta el Ministerio de Cultura, y
     * entonces la Orden de Servicio tiene que salir a nombre de Futurismo.
     *
     * Sale del mismo catálogo que el prestador, también para los internos: «Openperu Tickets»
     * es una parte de la empresa modelada como organización. Un solo catálogo, una sola
     * pregunta.
     */
    #[Groups(['componente:item:read', 'componente:write'])]
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: TravelOrganizacion::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TravelOrganizacion $comprador = null;

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

    /**
     * @return list<array{language?: string, content?: string|null}>
     */
    public function getTitulo(): array
    {
        return $this->titulo;
    }

    /**
     * @param list<array{language?: string, content?: string|null}> $titulo
     */
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

    public function getNombreParaPrestador(): ?string
    {
        return $this->nombreParaPrestador;
    }

    public function setNombreParaPrestador(?string $nombreParaPrestador): self
    {
        $this->nombreParaPrestador = $nombreParaPrestador;
        return $this;
    }

    public function getPrestador(): ?TravelOrganizacion { return $this->prestador; }
    public function setPrestador(?TravelOrganizacion $v): self { $this->prestador = $v; return $this; }

    public function getPrestadorServicio(): ?TravelOrganizacionServicio { return $this->prestadorServicio; }
    public function setPrestadorServicio(?TravelOrganizacionServicio $v): self { $this->prestadorServicio = $v; return $this; }

    public function getComprador(): ?TravelOrganizacion { return $this->comprador; }
    public function setComprador(?TravelOrganizacion $v): self { $this->comprador = $v; return $this; }

    // ─────────────────────────────────────────────────────────────────────────
    // LOS NOMBRES, PARA QUIEN CONSUME LA API
    //
    // Las tres relaciones de arriba viajan como IRI (`readableLink: false`), que es lo
    // correcto para no arrastrar la ficha entera ni abrir recursión. Pero el editor de
    // cotizaciones tiene que CONGELAR el nombre en su snapshot, y con un IRI tendría que
    // resolverlo una petición por tarifa.
    //
    // Así que el nombre viaja al lado, plano. Es barato —ya está cargado— y evita al
    // consumidor una cascada de peticiones para escribir un campo de texto.
    // ─────────────────────────────────────────────────────────────────────────

    /** Nombre comercial de quién presta, para congelarlo sin resolver el IRI. */
    #[Groups(['componente:item:read'])]
    public function getPrestadorNombre(): ?string
    {
        return $this->prestador?->getNombreComercial();
    }

    /** Nombre del servicio contratado (ej. «Habitación Matrimonial Standard»). */
    #[Groups(['componente:item:read'])]
    public function getPrestadorServicioNombre(): ?string
    {
        return $this->prestadorServicio?->getNombre();
    }

    /**
     * Nombre comercial de a quién se le encarga la compra.
     *
     * ⚠️ **No cae al prestador cuando está vacío, a propósito.** Aquí se dice lo que hay
     * escrito; quien decide qué significa el vacío es la cotización, que ya tiene esa cascada
     * en `CotizacionCotcomponente::resolverComprador()`. Rellenarlo desde el catálogo
     * duplicaría la regla en dos sitios y las dos copias se separarían.
     */
    #[Groups(['componente:item:read'])]
    public function getCompradorNombre(): ?string
    {
        return $this->comprador?->getNombreComercial();
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

        // 3. El servicio elegido tiene que ser del prestador de ESTA tarifa.
        //
        // Sin esto se guarda «Hotel A» con «habitación doble del Hotel B»: no falla al
        // escribir y sale mal en la cotización, que es el peor momento para enterarse.
        if ($this->prestadorServicio !== null
            && $this->prestador !== null
            && $this->prestadorServicio->getOrganizacion() !== $this->prestador
        ) {
            $context->buildViolation('El servicio seleccionado no pertenece al prestador de esta tarifa.')
                ->atPath('prestadorServicio')
                ->addViolation();
        }
    }
}