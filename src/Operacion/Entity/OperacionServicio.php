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
use App\Cotizacion\Enum\ComponenteEstadoEnum;
use App\Entity\Maestro\MaestroMoneda;
use App\Entity\Trait\IdTrait;
use App\Operacion\Enum\EstadoOperacionEnum;
use App\Operacion\Enum\EstadoReservaProveedorEnum;
use App\Entity\Trait\TimestampTrait;
use App\Security\Roles;
use App\Travel\Enum\ComponenteModoEnum;
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
    'estadoReservaProveedor'                 => 'exact',
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
// Un componente, una fila. La idempotencia del snapshot lo garantizaba con un
// findOneBy(), que es una comprobación y no una restricción: dos `aplicar` a la vez
// —doble clic con latencia, dos operadores sobre el mismo plan— pasaban los dos y
// creaban la fila los dos. Con filas duplicadas en el cuadro se compra dos veces.
#[ORM\UniqueConstraint(name: 'uniq_ops_servicio_componente', columns: ['cotizacion_componente_id'])]
#[ORM\HasLifecycleCallbacks]
class OperacionServicio
{
    use IdTrait;
    use TimestampTrait;

    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\ManyToOne(targetEntity: OperacionOrdenServicio::class, inversedBy: 'operacionServicios')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?OperacionOrdenServicio $ordenServicio = null;

    // ⚠️ Las tres referencias al árbol de la cotización eran RESTRICT, y eso hacía
    // IMPOSIBLE el flujo que el módulo promete. El editor guarda el árbol entero con un
    // PUT: lo que ya no está en el payload se orfaniza y Doctrine lo borra. Así que
    // quitar un día, borrar un componente o —lo más común de todo— reemplazar una
    // tarifa en una cotización ya confirmada terminaba en violación de FK: un 500 en
    // mitad del guardado, sin mensaje que explicara nada.
    //
    // CASCADE en las tres: si el componente, el día o el expediente desaparecen de la
    // cotización, la fila de tráfico se queda sin origen y no significa nada. Se pierde
    // lo que el operador hubiera escrito en ella, sí — pero es la consecuencia de que
    // una persona decidiera que ese servicio ya no va, no un accidente del sistema.
    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\ManyToOne(targetEntity: CotizacionFile::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?CotizacionFile $file = null;

    #[Groups(['operacion:item:read'])]
    #[ORM\ManyToOne(targetEntity: CotizacionCotservicio::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?CotizacionCotservicio $cotizacionServicio = null;

    #[Groups(['operacion:item:read'])]
    #[ORM\ManyToOne(targetEntity: CotizacionCotcomponente::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?CotizacionCotcomponente $cotizacionComponente = null;

    /**
     * Tarifa de la que salió el costo. **Nullable**: un componente sin tarifa también
     * genera fila.
     *
     * Un hotel que el pasajero reserva por su cuenta no se le compra a nadie y no lleva
     * tarifa, pero el transportista y el guía necesitan saber dónde recogerlo. Excluirlo
     * dejaba el cuadro de tráfico sin la referencia más básica del día. Ver
     * isSoloReferencia() y docs/Operacion.md §3.3.
     */
    #[Groups(['operacion:item:read', 'operacion:write'])]
    // SET NULL y no CASCADE: sustituir una tarifa por otra es rutina de negociación, y
    // la fila tiene que sobrevivir para que la reconciliación le ponga la nueva. Con
    // CASCADE, cambiar de proveedor borraría el servicio del cuadro de tráfico.
    #[ORM\ManyToOne(targetEntity: CotizacionCottarifa::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
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

    // ─────────────────────────────────────────────────────────────────────────
    // PRESTADOR — quién opera, frente a proveedor* = a quién se le compra
    //
    // Los dos conviven porque en Operaciones hay dos consumidores con necesidades
    // opuestas: la Orden de Servicio necesita al proveedor comercial (a quién le
    // mando la solicitud y le pago) y el cuadro de tráfico necesita al prestador
    // (dónde recojo, quién opera). Un solo campo haciendo los dos trabajos era lo
    // que obligaba a escribir el hotel a mano como si fuera una observación.
    //
    // Viene resuelto de CotizacionCotcomponente::resolverPrestador() — componente →
    // día → proveedor de la tarifa — así que en el caso normal coincide con
    // proveedorNombreManual y nadie tiene que llenar nada.
    // ─────────────────────────────────────────────────────────────────────────

    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $prestadorMaestroId = null;

    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $prestadorNombre = null;

    /** Teléfono y dirección congelados: es lo que el transportista necesita al operar. */
    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $prestadorTelefono = null;

    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $prestadorDireccion = null;

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

    /** Nullable por la misma razón que $cotizacionTarifa: sin tarifa no hay moneda. */
    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\ManyToOne(targetEntity: MaestroMoneda::class)]
    #[ORM\JoinColumn(name: 'moneda_cotizada', referencedColumnName: 'id', nullable: true)]
    private ?MaestroMoneda $monedaCotizada = null;

    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $costoRealOperativo = '0.00';

    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\ManyToOne(targetEntity: MaestroMoneda::class)]
    #[ORM\JoinColumn(name: 'moneda_real', referencedColumnName: 'id', nullable: true)]
    private ?MaestroMoneda $monedaReal = null;

    /**
     * ¿El proveedor ya me confirmó este servicio? Es el estado que dice si **la plaza existe**.
     *
     * ⚠️ Uno de TRES estados que suenan parecido y no lo son. Al pasajero se le contesta con
     * éste; los otros dos no significan nada para quien viaja:
     *   - `$estadoOperacion` → ¿el servicio ya ocurrió?
     *   - `OperacionOrdenServicio::$estadoOs` → ¿en qué punto está el papeleo de la compra?
     *
     * Se llamaba `estadoReserva` a secas y era una trampa: en este sistema «reserva» es la del
     * huésped ({@see \App\Pms\Entity\PmsReserva}), y ésta es la que le hago YO al proveedor.
     */
    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\Column(name: 'estado_reserva_proveedor', type: 'string', length: 30, enumType: EstadoReservaProveedorEnum::class, options: ['default' => 'sin-solicitar'])]
    private EstadoReservaProveedorEnum $estadoReservaProveedor = EstadoReservaProveedorEnum::SIN_SOLICITAR;

    #[Groups(['operacion:item:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 30, enumType: EstadoOperacionEnum::class, options: ['default' => 'pendiente'])]
    private EstadoOperacionEnum $estadoOperacion = EstadoOperacionEnum::PENDIENTE;

    /**
     * Valores EXACTOS que escribió el snapshot la última vez, en forma escalar.
     *
     * Es lo que hace posible reconciliar en vez de borrar y recrear. Sin esta foto no
     * se puede responder la única pregunta que importa al comparar una fila con la
     * cotización: si difieren, **¿quién de los dos se movió?** Con ella:
     *
     *   actual == origen  →  el operador no lo tocó  → la cotización manda, se actualiza
     *   actual != origen  →  el operador lo editó    → CONFLICTO, decide una persona
     *
     * No se serializa a la API: es memoria interna del reconciliador, no un dato
     * operativo. Ver BibliaReconciliacionService y docs/Operacion.md §3.5.
     *
     * @var array<string, string|int|null>
     */
    #[ORM\Column(type: 'json')]
    private array $snapshotOrigen = [];

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

    /**
     * La fila está en el cuadro de tráfico como REFERENCIA, no como compra.
     *
     * Dos casos, y los dos comparten la misma consecuencia: no se le pide nada a ningún
     * proveedor, así que no puede entrar en una Orden de Servicio.
     *
     *  - **Sin tarifa.** Nadie lo cotizó porque no se compra.
     *  - **`no_incluido`.** El pasajero lo paga por su cuenta (el hotel que reservó él).
     *
     * Y sin embargo tienen que verse: el transportista necesita el hotel para el recojo y
     * el guía necesita el vuelo para saber a qué hora deja de tener al grupo. Excluirlos
     * dejaba el cuadro sin la referencia más básica del día. Ver docs/Operacion.md §3.3.
     *
     * Es un cálculo y no una columna a propósito: se deriva de datos que ya están en la
     * fila, y duplicarlo en una columna abriría la puerta a que las dos se contradigan.
     * El frontend lo consume desde la API — la regla no se reimplementa en TypeScript.
     */
    #[Groups(['operacion:item:read'])]
    public function isSoloReferencia(): bool
    {
        return $this->cotizacionTarifa === null
            || $this->modoComponente === ComponenteModoEnum::NO_INCLUIDO->value;
    }

    /**
     * ¿Se le puede pedir formalmente a un proveedor?
     *
     * Es más estrecho que `!isSoloReferencia()`: además de lo que no se compra, quedan
     * fuera los servicios que **ya no se operan**. Un componente cancelado por el
     * cliente o sustituido por otro conserva su tarifa, así que pasaba todas las
     * comprobaciones anteriores y podía colarse en una Orden de Servicio junto al resto
     * del día — la vista los atenúa, pero atenuar no impide marcar la casilla. El
     * resultado era pedirle y pagarle a un proveedor un servicio que nadie va a usar.
     */
    public function esComprable(): bool
    {
        return !$this->isSoloReferencia()
            && $this->estadoComponente !== ComponenteEstadoEnum::CANCELADO->value
            && $this->modoComponente !== ComponenteModoEnum::REEMPLAZADO->value;
    }

    /** Motivo por el que no se puede comprar, o null si sí se puede. Se muestra al operador. */
    public function motivoNoComprable(): ?string
    {
        if ($this->isSoloReferencia()) {
            return 'está en La Biblia sólo como referencia (no incluido o sin tarifa): no se le compra a ningún proveedor';
        }
        if ($this->estadoComponente === ComponenteEstadoEnum::CANCELADO->value) {
            return 'está cancelado en la cotización: pedirlo sería comprar algo que el cliente no quiere';
        }
        if ($this->modoComponente === ComponenteModoEnum::REEMPLAZADO->value) {
            return 'fue reemplazado por otro servicio en la cotización';
        }

        return null;
    }

    public function getOrdenServicio(): ?OperacionOrdenServicio { return $this->ordenServicio; }

    public function setOrdenServicio(?OperacionOrdenServicio $ordenServicio): self
    {
        // La regla vive aquí y no sólo en la vista: una OS es una solicitud formal de
        // compra a un proveedor, y meterle un servicio que nadie compra produce un
        // documento con un importe que no se debe. API Platform mapea DomainException
        // a 422 (config/packages/api_platform.yaml), así que el PATCH devuelve el motivo.
        if ($ordenServicio !== null && ($motivo = $this->motivoNoComprable()) !== null) {
            throw new \DomainException(sprintf(
                '«%s» %s, y no puede entrar en una Orden de Servicio.',
                $this->descripcionServicio ?? 'El servicio',
                $motivo
            ));
        }

        // Coherencia con la cabecera. Vivía SÓLO en `conflictoSeleccion` del navegador,
        // así que dos pestañas abiertas o cualquier otro consumidor de la API podían
        // armar una OS con filas de dos expedientes o de dos monedas — un documento que
        // el proveedor no puede firmar y un importe que no cuadra con sus líneas.
        if ($ordenServicio !== null) {
            $fileOs = $ordenServicio->getFile();
            if ($fileOs !== null && $this->file !== null && $fileOs->getId() != $this->file->getId()) {
                throw new \DomainException(sprintf(
                    'La Orden de Servicio %s es del expediente «%s» y «%s» pertenece a otro. Una OS es una solicitud sobre un solo expediente.',
                    $ordenServicio->getNumeroOs() ?? '(sin número)',
                    $fileOs->getNombreGrupo() ?? '—',
                    $this->descripcionServicio ?? 'este servicio'
                ));
            }

            $monedaOs = $ordenServicio->getMonedaOs();
            if ($monedaOs !== null && $this->monedaCotizada !== null && $monedaOs->getId() !== $this->monedaCotizada->getId()) {
                throw new \DomainException(sprintf(
                    'La Orden de Servicio %s está en %s y «%s» está cotizado en %s. Mezclar monedas en una misma orden deja un total que no suma.',
                    $ordenServicio->getNumeroOs() ?? '(sin número)',
                    $monedaOs->getId(),
                    $this->descripcionServicio ?? 'este servicio',
                    $this->monedaCotizada->getId()
                ));
            }
        }

        $this->ordenServicio = $ordenServicio;

        return $this;
    }

    /**
     * Guarda simétrica de la anterior: una fila que YA está en una Orden de Servicio no
     * puede dejar de ser comprable.
     *
     * `setOrdenServicio()` sólo vigila la entrada, y con eso no basta: la reconciliación
     * puede aprobar un `modoComponente → no_incluido`, o dejar la tarifa en null al
     * desaparecer del árbol, y la fila se convertía en referencia **dentro** de una OS ya
     * emitida. La OS quedaba conteniendo un importe que no se debe, y `totalOs` seguía
     * sumándolo.
     *
     * Se llama desde prePersist/preUpdate: cubre por igual la reconciliación, un PATCH
     * suelto y el comando de consola, que es justo lo que la guarda de entrada no cubría.
     */
    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function verificarCoherenciaConOrdenServicio(): void
    {
        if ($this->ordenServicio === null) {
            return;
        }

        if (($motivo = $this->motivoNoComprable()) !== null) {
            throw new \DomainException(sprintf(
                '«%s» pertenece a la Orden de Servicio %s y ahora %s. Sácalo de la orden antes de aplicar este cambio.',
                $this->descripcionServicio ?? 'El servicio',
                $this->ordenServicio->getNumeroOs() ?? '(sin número)',
                $motivo
            ));
        }
    }

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

    public function getPrestadorMaestroId(): ?string { return $this->prestadorMaestroId; }
    public function setPrestadorMaestroId(?string $v): self { $this->prestadorMaestroId = $v; return $this; }

    public function getPrestadorNombre(): ?string { return $this->prestadorNombre; }
    public function setPrestadorNombre(?string $v): self { $this->prestadorNombre = $v; return $this; }

    public function getPrestadorTelefono(): ?string { return $this->prestadorTelefono; }
    public function setPrestadorTelefono(?string $v): self { $this->prestadorTelefono = $v; return $this; }

    public function getPrestadorDireccion(): ?string { return $this->prestadorDireccion; }
    public function setPrestadorDireccion(?string $v): self { $this->prestadorDireccion = $v; return $this; }

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

    public function getEstadoReservaProveedor(): EstadoReservaProveedorEnum { return $this->estadoReservaProveedor; }
    public function setEstadoReservaProveedor(EstadoReservaProveedorEnum $estadoReservaProveedor): self { $this->estadoReservaProveedor = $estadoReservaProveedor; return $this; }

    public function getEstadoOperacion(): EstadoOperacionEnum { return $this->estadoOperacion; }
    public function setEstadoOperacion(EstadoOperacionEnum $estadoOperacion): self { $this->estadoOperacion = $estadoOperacion; return $this; }

    /** @return array<string, string|int|null> */
    public function getSnapshotOrigen(): array { return $this->snapshotOrigen; }

    /** @param array<string, string|int|null> $v */
    public function setSnapshotOrigen(array $v): self { $this->snapshotOrigen = $v; return $this; }
}
