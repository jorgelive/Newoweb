<?php

declare(strict_types=1);

namespace App\Operacion\Entity;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use App\Cotizacion\Entity\CotizacionCotcomponente;
use App\Cotizacion\Entity\CotizacionCotservicio;
use App\Cotizacion\Entity\CotizacionCottarifa;
use App\Cotizacion\Entity\CotizacionFile;
use App\Entity\Maestro\MaestroMoneda;
use App\Entity\Trait\IdTrait;
use App\Operacion\Enum\EstadoOperacionEnum;
use App\Operacion\Enum\EstadoReservaEnum;
use App\Entity\Trait\TimestampTrait;
use App\Security\Roles;
use App\Travel\Enum\ComponenteTipoEnum;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;

#[ApiResource(
    operations: [
        new GetCollection(
            security: "is_granted('" . Roles::OPERACIONES_SHOW . "')"
        ),
        new Get(
            security: "is_granted('" . Roles::OPERACIONES_SHOW . "')"
        ),
        new Post(
            securityPostDenormalize: "is_granted('" . Roles::OPERACIONES_WRITE . "')",
            securityPostDenormalizeMessage: 'No tienes permiso para crear servicios operativos.'
        ),
        new Put(
            security: "is_granted('" . Roles::OPERACIONES_WRITE . "')",
            securityMessage: 'No tienes permiso para editar servicios operativos.'
        ),
        new Patch(
            security: "is_granted('" . Roles::OPERACIONES_WRITE . "')",
            securityMessage: 'No tienes permiso para actualizar servicios operativos.'
        ),
        new Delete(
            security: "is_granted('" . Roles::OPERACIONES_DELETE . "')",
            securityMessage: 'No tienes permiso para eliminar servicios operativos.'
        ),
    ],
    routePrefix: '/ops',
    normalizationContext: ['groups' => ['operacion:item:read', 'timestamp:read']],
    denormalizationContext: ['groups' => ['operacion:write']],
    // La Biblia se lee siempre en orden de despacho: primero el día, luego la hora.
    // Sin esto la colección llega en orden arbitrario de la BD y el cuadro es inservible.
    order: ['fechaServicio' => 'ASC', 'horaRecojoReal' => 'ASC'],
    paginationClientItemsPerPage: true,
    paginationItemsPerPage: 100
)]
#[ApiFilter(SearchFilter::class, properties: [
    'ordenServicio'                 => 'exact',
    'file'                          => 'exact',
    // Filtro por cotización: navega la asociación cotservicio → cotizacion.
    'cotizacionServicio.cotizacion' => 'exact',
    'estadoReserva'                 => 'exact',
    'estadoOperacion'               => 'exact',
    'proveedorMaestroId'            => 'exact',
    'tipoComponente'                => 'exact',
    'modoComponente'                => 'exact',
    'estadoComponente'              => 'exact',
])]
// fechaServicio necesita DateFilter, no SearchFilter: 'exact' sólo permite un día suelto
// y el tráfico se planifica por rango (fechaServicio[after] / fechaServicio[before]).
#[ApiFilter(DateFilter::class, properties: ['fechaServicio'])]
#[ApiFilter(OrderFilter::class, properties: ['fechaServicio', 'horaRecojoReal', 'tipoComponente', 'descripcionServicio'])]
#[ORM\Entity]
#[ORM\Table(name: 'operacion_servicio')]
#[ORM\Index(columns: ['fecha_servicio'], name: 'idx_ops_servicio_fecha')]
#[ORM\Index(columns: ['tipo_componente'], name: 'idx_ops_servicio_tipo')]
#[ORM\HasLifecycleCallbacks]
class OperacionServicio
{
    use IdTrait;
    use TimestampTrait;

    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\ManyToOne(targetEntity: OperacionOrdenServicio::class, inversedBy: 'operacionServicios')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?OperacionOrdenServicio $ordenServicio = null;

    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\ManyToOne(targetEntity: CotizacionFile::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?CotizacionFile $file = null;

    #[Groups(['operacion:item:read'])]
    #[ORM\ManyToOne(targetEntity: CotizacionCotservicio::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?CotizacionCotservicio $cotizacionServicio = null;

    #[Groups(['operacion:item:read'])]
    #[ORM\ManyToOne(targetEntity: CotizacionCotcomponente::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?CotizacionCotcomponente $cotizacionComponente = null;

    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\ManyToOne(targetEntity: CotizacionCottarifa::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?CotizacionCottarifa $cotizacionTarifa = null;

    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'date_immutable')]
    private ?\DateTimeImmutable $fechaServicio = null;

    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    private ?string $horaRecojoReal = null;

    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $proveedorMaestroId = null;

    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $proveedorNombreManual = null;

    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 255)]
    private string $descripcionServicio;

    /**
     * Nombre del CotizacionCotservicio padre (el "día" del itinerario) en español.
     *
     * Se denormaliza en vez de serializar el cotservicio embebido porque su nombre vive en
     * un array i18n; el tráfico sólo necesita la etiqueta en español para saber a qué bloque
     * del itinerario pertenece la fila.
     */
    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $contextoServicio = null;

    /**
     * Snapshot de CotizacionCotcomponente::$tipo (valores de ComponenteTipoEnum).
     *
     * Se guarda como string suelto y no como enumType porque el origen también lo es
     * (?string): tipos legacy o vacíos no deben reventar la generación de La Biblia.
     * Es lo que permite distinguir un traslado de un desayuno sin volver a la cotización.
     */
    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $tipoComponente = null;

    /** Snapshot de ComponenteModoEnum: incluido, no_incluido, cortesia, reemplazado. */
    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $modoComponente = null;

    /** Snapshot de ComponenteEstadoEnum en el momento de confirmar la cotización. */
    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $estadoComponente = null;

    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'integer')]
    private int $cantidadPax = 1;

    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $montoVenta = '0.00';

    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $costoCotizado = '0.00';

    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\ManyToOne(targetEntity: MaestroMoneda::class)]
    #[ORM\JoinColumn(name: 'moneda_cotizada', referencedColumnName: 'id', nullable: false)]
    private ?MaestroMoneda $monedaCotizada = null;

    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $costoRealOperativo = '0.00';

    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\ManyToOne(targetEntity: MaestroMoneda::class)]
    #[ORM\JoinColumn(name: 'moneda_real', referencedColumnName: 'id', nullable: false)]
    private ?MaestroMoneda $monedaReal = null;

    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 30, enumType: EstadoReservaEnum::class, options: ['default' => 'sin-solicitar'])]
    private EstadoReservaEnum $estadoReserva = EstadoReservaEnum::SIN_SOLICITAR;

    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 30, enumType: EstadoOperacionEnum::class, options: ['default' => 'pendiente'])]
    private EstadoOperacionEnum $estadoOperacion = EstadoOperacionEnum::PENDIENTE;

    public function __construct()
    {
        $this->initializeId();
    }

    #[Groups(['operacion:item:read'])]
    public function getId(): ?Uuid { return $this->id; }

    #[Groups(['operacion:write'])]
    public function setId(Uuid|string $id): self
    {
        $this->id = is_string($id) ? Uuid::fromString($id) : $id;
        return $this;
    }

    public function getOrdenServicio(): ?OperacionOrdenServicio { return $this->ordenServicio; }
    public function setOrdenServicio(?OperacionOrdenServicio $ordenServicio): self { $this->ordenServicio = $ordenServicio; return $this; }

    public function getFile(): ?CotizacionFile { return $this->file; }
    public function setFile(?CotizacionFile $file): self { $this->file = $file; return $this; }

    public function getCotizacionServicio(): ?CotizacionCotservicio { return $this->cotizacionServicio; }
    public function setCotizacionServicio(?CotizacionCotservicio $cotizacionServicio): self { $this->cotizacionServicio = $cotizacionServicio; return $this; }

    public function getCotizacionComponente(): ?CotizacionCotcomponente { return $this->cotizacionComponente; }
    public function setCotizacionComponente(?CotizacionCotcomponente $cotizacionComponente): self { $this->cotizacionComponente = $cotizacionComponente; return $this; }

    public function getCotizacionTarifa(): ?CotizacionCottarifa { return $this->cotizacionTarifa; }
    public function setCotizacionTarifa(?CotizacionCottarifa $cotizacionTarifa): self { $this->cotizacionTarifa = $cotizacionTarifa; return $this; }

    public function getFechaServicio(): ?\DateTimeImmutable { return $this->fechaServicio; }
    public function setFechaServicio(\DateTimeImmutable $fechaServicio): self { $this->fechaServicio = $fechaServicio; return $this; }

    public function getHoraRecojoReal(): ?string { return $this->horaRecojoReal; }
    public function setHoraRecojoReal(?string $horaRecojoReal): self { $this->horaRecojoReal = $horaRecojoReal; return $this; }

    public function getProveedorMaestroId(): ?string { return $this->proveedorMaestroId; }
    public function setProveedorMaestroId(?string $proveedorMaestroId): self { $this->proveedorMaestroId = $proveedorMaestroId; return $this; }

    public function getProveedorNombreManual(): ?string { return $this->proveedorNombreManual; }
    public function setProveedorNombreManual(?string $proveedorNombreManual): self { $this->proveedorNombreManual = $proveedorNombreManual; return $this; }

    public function getDescripcionServicio(): string { return $this->descripcionServicio; }
    public function setDescripcionServicio(string $descripcionServicio): self { $this->descripcionServicio = $descripcionServicio; return $this; }

    public function getContextoServicio(): ?string { return $this->contextoServicio; }
    public function setContextoServicio(?string $contextoServicio): self { $this->contextoServicio = $contextoServicio; return $this; }

    public function getTipoComponente(): ?string { return $this->tipoComponente; }
    public function setTipoComponente(?string $tipoComponente): self { $this->tipoComponente = $tipoComponente; return $this; }

    public function getModoComponente(): ?string { return $this->modoComponente; }
    public function setModoComponente(?string $modoComponente): self { $this->modoComponente = $modoComponente; return $this; }

    public function getEstadoComponente(): ?string { return $this->estadoComponente; }
    public function setEstadoComponente(?string $estadoComponente): self { $this->estadoComponente = $estadoComponente; return $this; }

    /**
     * Prioridad de despacho heredada de ComponenteTipoEnum::prioridad().
     *
     * Se expone calculada (no se persiste) para que la vista ordene las filas sin horario
     * por relevancia operativa — guiado/transporte antes que tickets — sin duplicar la
     * tabla de prioridades en TypeScript. Los tipos desconocidos van al final.
     */
    #[Groups(['operacion:item:read'])]
    public function getPrioridadOperativa(): int
    {
        return ComponenteTipoEnum::tryFrom($this->tipoComponente ?? '')?->prioridad() ?? 9;
    }

    public function getCantidadPax(): int { return $this->cantidadPax; }
    public function setCantidadPax(int $cantidadPax): self { $this->cantidadPax = $cantidadPax; return $this; }

    public function getMontoVenta(): string { return $this->montoVenta; }
    public function setMontoVenta(string $montoVenta): self { $this->montoVenta = $montoVenta; return $this; }

    public function getCostoCotizado(): string { return $this->costoCotizado; }
    public function setCostoCotizado(string $costoCotizado): self { $this->costoCotizado = $costoCotizado; return $this; }

    public function getMonedaCotizada(): ?MaestroMoneda { return $this->monedaCotizada; }
    public function setMonedaCotizada(?MaestroMoneda $monedaCotizada): self { $this->monedaCotizada = $monedaCotizada; return $this; }

    public function getCostoRealOperativo(): string { return $this->costoRealOperativo; }
    public function setCostoRealOperativo(string $costoRealOperativo): self { $this->costoRealOperativo = $costoRealOperativo; return $this; }

    public function getMonedaReal(): ?MaestroMoneda { return $this->monedaReal; }
    public function setMonedaReal(?MaestroMoneda $monedaReal): self { $this->monedaReal = $monedaReal; return $this; }

    public function getEstadoReserva(): EstadoReservaEnum { return $this->estadoReserva; }
    public function setEstadoReserva(EstadoReservaEnum $estadoReserva): self { $this->estadoReserva = $estadoReserva; return $this; }

    public function getEstadoOperacion(): EstadoOperacionEnum { return $this->estadoOperacion; }
    public function setEstadoOperacion(EstadoOperacionEnum $estadoOperacion): self { $this->estadoOperacion = $estadoOperacion; return $this; }
}
